# Special User Importer

## This project is designed to fetch user data from an external API and save them to a database. The ImportUsersJob handles the import process by fetching users in batches and updating or adding them to the database.
Table of Contents:
- Installation
- Usage
- Scalability Considerations

# Installation

## Clone the repository:

```bash
git clone <repository-url>
```

## Navigate to the project directory:

```bash
cd <project-directory>
```

## Install the necessary packages:

```bash
composer install
```

# Usage

## To start the user import process, dispatch the ImportUsersJob:

```php
ImportUsersJob::dispatch();
```

This job fetches users from the API endpoint (https://randomuser.me/api/) and processes them in chunks, updating existing users or adding new ones as necessary.
