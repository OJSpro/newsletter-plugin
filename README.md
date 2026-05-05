# Newsletter Subscription Plugin for OMP 3.4

This plugin enables a newsletter subscription feature for **Open Monograph Press (OMP) 3.4** and **Open Journal Systems (OJS) 3.4** by registering subscribers as "Readers" within the system.

## Features

-   **Seamless Subscription**: Provides a dedicated endpoint (`/newsletter/subscribe`) for handling subscription requests.
-   **Automatic User Creation**: Automatically creates a system account for new subscribers and assigns them the "Reader" role.
-   **Email Validation**: Leverages the core PKP email validation workflow to ensure that subscribers verify their email addresses before being fully activated.
-   **AJAX Ready**: Returns JSON responses, making it easy to integrate with custom front-end subscription forms or popups.
-   **Security**: Includes validation for email formats and mandatory name fields, and uses random secure passwords for newly created accounts.
-   **Multi-Journal/Press Support**: Handles subscriptions on a per-context basis.

## Installation

1.  Download the plugin files.
2.  Upload the `newsletter` folder to your installation's `plugins/generic/` directory.
3.  Log in as a Site Administrator.
4.  Go to **Settings > Website > Plugins**.
5.  Locate the **Newsletter Subscription** plugin and enable it.

## Usage

Once enabled, you can send POST requests to the following URL:
`your-site.com/index.php/press_path/newsletter/subscribe`

### Required POST Parameters:
-   `email`: The subscriber's email address.
-   `firstname`: The subscriber's first name.
-   `lastname`: The subscriber's last name.

### Example Integration (jQuery/AJAX):

```javascript
$.post('/index.php/jlsr/newsletter/subscribe', {
    email: 'user@example.com',
    firstname: 'John',
    lastname: 'Doe'
}, function(response) {
    if (response.status === 'success') {
        alert(response.message);
    } else {
        alert('Error: ' + response.message);
    }
});
```

## How it Works

1.  **Request Receipt**: The plugin intercepts requests to the `newsletter/subscribe` path.
2.  **User Check**: It checks if a user with that email already exists in the system.
3.  **Account Creation**: If the user is new, it creates a profile, assigns the "Reader" role, and generates a random password.
4.  **Verification**: If email validation is required in site settings, it sends an activation link to the user.
5.  **Role Assignment**: If the user exists but isn't a Reader in the current context, it adds the role for them.

## Requirements

-   **OMP 3.4.x** or **OJS 3.4.x**
-   PHP 8.0 or higher.

## License

This plugin is distributed under the GNU General Public License v3.

---
Developed by [OJSpro](https://github.com/OJSpro)
