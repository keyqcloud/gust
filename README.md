> [!WARNING]
> **Archived: no longer maintained.** This repository is read-only and kept for reference. It receives no further updates, including security fixes. The core of Kyte is in maintenance mode (security fixes through November 2027); see [kyte-php](https://github.com/keyqcloud/kyte-php).

**Getting Started**

Prior to running gust to initialize the database and Kyte, make sure the `config.php` as been created and the required database information supplied. Once `config.php` has been created and the minimal required information supplied, you are ready to initialize the database by running:

```
gust init db
```

Once the database initialized successfully, it is time to create a root account (master admin) using the following command:
```
init account [Account Name] [User's Name] [email] [password]
```

Make sure to replace the values in the square brakets.
