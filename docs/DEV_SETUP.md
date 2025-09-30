# Development Setup

This document describes how to get the PostSecret project up and running locally.

## Prerequisites

- Docker and Docker Compose installed.
- PHP 8.2 with Composer if you intend to run unit tests outside of Docker.

## Steps

1. **Clone the repository**:

   ```bash
   git clone https://example.com/postsecret.git
   cd postsecret
   ```

2. **Bring up the environment**:

   Use Docker Compose to start WordPress and MySQL:

   ```bash
   docker-compose up -d
   ```

   WordPress will be available at <http://localhost:8080>. The `wp-content` directory is mounted so you can develop the theme and plugin locally.

3. **Install PHP dependencies**:

   ```bash
   composer install
   ```

4. **Run tests and coding standards**:

   ```bash
   composer test
   vendor/bin/phpcs
   ```

Refer to the other documents in the `docs/` directory for guidelines on moderation and tag governance.
