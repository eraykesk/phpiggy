# PHPiggy

A personal expense tracking web application built with a custom PHP 8 MVC framework. Built as a capstone project for *The Complete Modern PHP Developer* course.

## Tech Stack

- PHP 8.2 + Apache
- MySQL 8
- Custom MVC framework (no Laravel/Symfony)
- Composer / PSR-4 autoloading

## Running with Docker

### Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) installed and running

### Steps

```bash
# 1. Clone the repo
git clone https://github.com/eraykesk/phpiggy.git
cd phpiggy

# 2. Start all containers (app + MySQL + Adminer)
docker compose up --build

# 3. Open the app in your browser
#    http://localhost:8080
```

The database schema is applied automatically on first startup via `database.sql`.

### Services

| Service | URL | Description |
|---------|-----|-------------|
| PHP App | http://localhost:8080 | Main application |
| Adminer | http://localhost:8081 | Database UI |
| MySQL   | localhost:3306 | Direct DB access |

### Adminer login

- **Server**: db
- **Username**: phpiggy
- **Password**: secret
- **Database**: phpiggy

### Stopping

```bash
docker compose down          # Stop containers, keep DB data
docker compose down -v       # Stop containers AND delete DB data (full reset)
```

## Project Structure

```
public/         # Web root (Apache DocumentRoot)
src/
  App/          # Application code (Controllers, Services, Views, Middleware)
  Framework/    # Custom MVC framework (Router, Container, Database, etc.)
storage/
  uploads/      # Receipt file uploads
database.sql    # Database schema
```

## Environment Variables

Copy `.env.example` to `.env` and adjust values if needed (defaults work with Docker out of the box).

| Variable | Default | Description |
|----------|---------|-------------|
| APP_ENV | development | Environment |
| DB_DRIVER | mysql | PDO driver |
| DB_HOST | db | MySQL container name |
| DB_PORT | 3306 | MySQL port |
| DB_NAME | phpiggy | Database name |
| DB_USER | phpiggy | DB username |
| DB_PASS | secret | DB password |
