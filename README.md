# API do Projeto Learnny

Esta é a API RESTful para a plataforma **Learnny**, um sistema de conexão entre alunos e professores particulares. Ela gerencia todos os dados, lógica de negócios, autenticação e integração com serviços externos.

## Funcionalidades Principais

* **Autenticação Tripla:** Sistema de autenticação seguro usando Laravel Passport para os três atores: Alunos (`User`), Professores (`Professor`) e `Admin`.
* **Ciclo de Agendamento Completo:** Endpoints para professores definirem sua disponibilidade, alunos consultarem horários livres e o ciclo completo de agendamento (solicitar, confirmar, rejeitar, cancelar, concluir).
* **Gestão de Professores:** CRUD de professores com fluxo de aprovação de cadastro pelo Admin.
* **Integração com Moodle:** Provisionamento automático de usuários (alunos e professores) e criação dinâmica de cursos no Moodle via API.
* **Gestão de Matérias (Admin):** CRUD para as matérias disponíveis na plataforma.

## Stack de Tecnologias

* **PHP 8.2+** / **Laravel 11**
* **Laravel Passport** (para autenticação OAuth2)
* **Banco de Dados:**
  * **Local:** MySQL (via Laragon)
  * **Produção:** PostgreSQL (via Supabase)
* **Deploy de Produção:** [**Railway**](https://railway.app/)

## 1. Configuração do Ambiente Local (Laragon + MySQL)

### 1.1. Clonar e Instalar

1. Clone o repositório:
   ```bash
   git clone https://github.com/VictorNBatista/learnny-backend.git
   cd learnny-backend
   ```

2. Instale as dependências do Composer:
    ```bash
    composer install
    ```

### 1.2. Configurar .env

1. Copie o arquivo de exemplo:
    ```bash
    cp .env.example .env
    ```

2. Gere a chave da aplicação:
    ```bash
    php artisan key:generate
    ```

3. Configure as variáveis do seu banco de dados local **MySQL** no `.env`:
    ```Ini
    DB_CONNECTION=mysql
    DB_HOST=127.0.0.1
    DB_PORT=3306
    DB_DATABASE=learnny_db
    DB_USERNAME=root
    DB_PASSWORD=
    ```

### 1.3. Banco de Dados e Autenticação

1. Rode as migrações (isso criará todas as tabelas):
    ```bash
    php artisan migrate
    ```

2. Configure o Laravel Passport:
    ```bash
    php artisan passport:keys
    php artisan passport:client --personal
    ```

3. (Opcional) Popule o banco com dados de teste (User, Professores e Admin):
    ```bash
    php artisan db:seed
    ```

### 1.4. Servir a Aplicação

1. Inicie o servidor local:
    ```bash
    php artisan serve
    ```

2. A API estará disponível em `http://localhost:8000`.

## 2. Deploy em Produção (Railway + Supabase)

1. Banco de Dados (Supabase):
    * Crie um projeto no Supabase (que usa PostgreSQL).
    * Vá em **Connect** na parte superior da página e copie as credenciais de conexão.

2. API (Railway):
    * Crie um novo projeto no Railway e vincule seu repositório do GitHub.
    * Vá até a aba **Variables** e adicione todas as variáveis do seu `.env`.
    * Importante: Altere as variáveis do banco para apontar para o Supabase:
      ```Ini
      DB_CONNECTION=pgsql
      DB_HOST=... (Host do Supabase)
      DB_PORT=5432
      DB_DATABASE=postgres
      DB_USERNAME=postgres
      DB_PASSWORD=... (Sua senha do Supabase)
      ```

    * Adicione a `APP_KEY` e as credenciais do Moodle (`MOODLE_URL`, `MOODLE_TOKEN`, etc.).
    * Em **Settings > Deploy**, ajuste o "Start Command" para rodar as migrações:
      ```bash
      php artisan config:cache && php artisan route:cache && php artisan migrate --force && php artisan passport:install --force && php artisan db:seed --force && php artisan serve --host=0.0.0.0 --port=$PORT
      ```
    
3. URL da API:
    * Vá em **Settings > Networking** e gere um domínio. A URL (ex: `learnny-api.up.railway.app`) será usada no seu front-end.

## 3. Endpoints Principais da API
A URL base é `http://localhost:8000/api` (local) ou sua URL do Railway (produção).

### Autenticação
* `POST /api/login`: Login do Aluno
* `POST /api/professor/login`: Login do Professor
* `POST /api/admin/login`: Login do Admin
* `POST /api/cadastrar`: Cadastro de novo Aluno
* `POST /api/professor/cadastrar`: Cadastro de novo Professor
* `POST /api/logout`: (Protegido) Invalida o token do usuário logado

### Agendamento
* `GET /api/professors/{id}/availabilities`: (Público) Retorna os slots de horários livres de um professor.
* `POST /api/appointments`: (Aluno) Cria uma nova solicitação de agendamento.
* `GET /api/appointments/my`: (Aluno) Lista os agendamentos do aluno logado.
* `PUT /api/appointments/{id}/cancel`: (Aluno) Cancela um agendamento.

### Gestão do Professor
* `GET /api/professor/availabilities`: (Professor) Retorna as regras de disponibilidade salvas.
* `POST /api/professor/availabilities`: (Professor) Salva/Atualiza as regras de disponibilidade.
* `GET /api/professor/appointments`: (Professor) Lista os agendamentos recebidos.
* `PUT /api/professor/appointments/{id}/confirm`: (Professor) Confirma um agendamento.
* `PUT /api/professor/appointments/{id}/reject`: (Professor) Rejeita um agendamento.

### Gestão do Admin
* `GET /api/admin/professores/pendentes`: (Admin) Lista professores aguardando aprovação.
* `PUT /api/admin/professores/aprovar/{id}`: (Admin) Aprova um professor.