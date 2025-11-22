<?php

namespace App\Services;

use App\Models\User;
use App\Models\Professor;
use App\Models\Subject;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MoodleService
{
    protected $moodleUrl;
    protected $token;
    protected $restEndpoint;

    public function __construct()
    {
        $this->moodleUrl = config('services.moodle.url');
        $this->token = config('services.moodle.token');
        $this->restEndpoint = "{$this->moodleUrl}/webservice/rest/server.php";
    }

    // ... provisionUser (pode manter igual) ...
    public function provisionUser(User $user, string $plainTextPassword): void
    {
        // (Seu código original do provisionUser aqui...)
        // Para economizar espaço, vou focar no provisionTeacher que é o crítico
        $nameParts = explode(' ', $user->name, 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? 'Aluno'; 

        $usersToCreate = [[
            'username' => $user->username,
            'password' => $plainTextPassword,
            'firstname' => $firstName,
            'lastname' => $lastName,
            'email' => $user->email,
            'auth' => 'manual',
            'lang' => 'pt_br',
        ]];

        $response = Http::asForm()->post($this->restEndpoint, [
            'wstoken' => $this->token,
            'wsfunction' => 'core_user_create_users',
            'moodlewsrestformat' => 'json',
            'users' => $usersToCreate,
        ]);

        if ($response->successful() && !$response->json('exception')) {
            $moodleUser = $response->json()[0];
            $user->moodle_id = $moodleUser['id'];
            $user->save();
            Log::info('Aluno provisionado no Moodle.', ['user_id' => $user->id]);
        } else {
            Log::error('Falha ao provisionar ALUNO no Moodle.', ['response' => $response->body()]);
        }
    }

    /**
     * Provisiona professor com lógica de COMPENSAÇÃO (Rollback manual no Moodle)
     */
    public function provisionTeacher(Professor $professor, string $plainTextPassword, array $learnnySubjectIds = []): bool
    {
        $moodleUserId = null;
        $createdCourseIds = [];

        try {
            // ----- PASSO 1: CRIAR O USUÁRIO -----
            
            $nameParts = explode(' ', $professor->name, 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? 'Professor';

            $usersToCreate = [[
                'username' => $professor->username,
                'password' => $plainTextPassword,
                'firstname' => $firstName,
                'lastname' => $lastName,
                'email' => $professor->email,
                'auth' => 'manual',
                'lang' => 'pt_br',
            ]];

            $responseCreate = Http::asForm()->post($this->restEndpoint, [
                'wstoken' => $this->token,
                'wsfunction' => 'core_user_create_users',
                'moodlewsrestformat' => 'json',
                'users' => $usersToCreate,
            ]);

            if ($responseCreate->failed() || $responseCreate->json('exception')) {
                // Se falhar aqui, não precisa limpar nada pois nada foi criado
                throw new \Exception('Falha ao criar usuário: ' . $responseCreate->body());
            }

            $moodleUserId = $responseCreate->json()[0]['id'];

            // ----- PASSO 2: CRIAR CURSOS E INSCREVER -----

            $defaultCategoryId = config('services.moodle.default_category_id');
            $teacherRoleId = config('services.moodle.teacher_role_id');

            foreach ($learnnySubjectIds as $subjectId) {
                $subject = Subject::find($subjectId);
                if (!$subject) continue;

                $courseFullName = "{$subject->name} - {$professor->name}";
                // Timestamp para garantir unicidade caso tente criar de novo
                $courseShortName = "learnny_{$subject->id}_{$professor->id}_" . time();

                // A. Criar Curso
                $responseCourse = Http::asForm()->post($this->restEndpoint, [
                    'wstoken' => $this->token,
                    'wsfunction' => 'core_course_create_courses',
                    'moodlewsrestformat' => 'json',
                    'courses' => [[
                        'fullname' => $courseFullName,
                        'shortname' => $courseShortName,
                        'categoryid' => $defaultCategoryId,
                        'visible' => 1,
                    ]],
                ]);

                if ($responseCourse->failed() || $responseCourse->json('exception')) {
                    throw new \Exception('Falha ao criar curso: ' . $responseCourse->body());
                }
                
                $moodleCourseId = $responseCourse->json()[0]['id'];
                $createdCourseIds[] = $moodleCourseId; // Registra para limpeza se der erro

                // B. Inscrever
                $responseEnrol = Http::asForm()->post($this->restEndpoint, [
                    'wstoken' => $this->token,
                    'wsfunction' => 'enrol_manual_enrol_users',
                    'moodlewsrestformat' => 'json',
                    'enrolments' => [[
                        'roleid' => $teacherRoleId,
                        'userid' => $moodleUserId,
                        'courseid' => $moodleCourseId,
                    ]],
                ]);

                if ($responseEnrol->failed() || $responseEnrol->json('exception')) {
                    throw new \Exception('Falha ao inscrever: ' . $responseEnrol->body());
                }
            }

            // SUCESSO TOTAL
            $professor->moodle_id = $moodleUserId;
            $professor->save();
            
            return true;

        } catch (\Exception $e) {
            Log::error('Erro no provisionamento Moodle. Iniciando limpeza...', ['error' => $e->getMessage()]);

            // --- LÓGICA DE LIMPEZA (ROLLBACK MANUAL) ---
            
            // 1. Deletar cursos criados (se houver)
            if (!empty($createdCourseIds)) {
                Http::asForm()->post($this->restEndpoint, [
                    'wstoken' => $this->token,
                    'wsfunction' => 'core_course_delete_courses',
                    'moodlewsrestformat' => 'json',
                    'courseids' => $createdCourseIds,
                ]);
                Log::info('Cursos do Moodle revertidos/deletados.', ['ids' => $createdCourseIds]);
            }

            // 2. Deletar usuário criado (se houver)
            if ($moodleUserId) {
                Http::asForm()->post($this->restEndpoint, [
                    'wstoken' => $this->token,
                    'wsfunction' => 'core_user_delete_users',
                    'moodlewsrestformat' => 'json',
                    'userids' => [$moodleUserId],
                ]);
                Log::info('Usuário do Moodle revertido/deletado.', ['id' => $moodleUserId]);
            }

            // Retorna false para acionar o rollback no banco local (Laravel)
            return false;
        }
    }
}