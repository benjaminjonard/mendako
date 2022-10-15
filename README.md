# Mendako

Quick project done over the course of a week.

Private, light booru like image board, supports multiple boards. 

## Installation
Copy the docker-compose.yml.dist in a docker-compose.yml file and adjust the environment variables to your need. 

### Available environment variables

| Name          | Description                                 | Possible values |
|---------------|---------------------------------------------|-----------------|
| DB_USER       | Your database user                          |                 |
| DB_PASSWORD   | Your database password                      |                 |
| DB_HOST       | Your database address                       |                 |
| DB_PORT       | Your database port                          |                 |
| DB_NAME       | Your database name                          |                 |
| DB_VERSION    | Your database server version (ex: 10.3)     |                 |
| APP_SECRET    | Random string used for security             |                 |
| APP_ENV       | Symfony environment, `prod` by default      | `prod` or `dev` |
| APP_DEBUG     | Activate Symfony debug mode, `0` by default | `0` or `1`      |
| HTTPS_ENABLED | If your instance uses https                 | `0` or `1`      |


## Screenshots
<p align="center">
    <img width="400px" src="https://user-images.githubusercontent.com/20560781/196007085-5be47dac-809c-4cff-bedd-deb4757c168e.png">
    <img width="400px" src="https://user-images.githubusercontent.com/20560781/196007150-e3cd4665-e6d9-4afb-8d11-41c155493f0c.png">
    <img width="400px" src="https://user-images.githubusercontent.com/20560781/196007132-3df3fdde-1d28-4906-88aa-74326d9f369f.png">
</p>
