# Easy Feedback Form – Simple, clean feedback & survey form

A simple yet powerful feedback form plugin for WordPress that allows you to collect and manage feedback from your website visitors.

## Features

- Clean and responsive feedback form
- Admin panel to view and manage submissions
- Unread/read status for submissions
- Notification counter in admin menu
- Theme-compatible styling using CSS variables
- Success messages with animations
- Secure form submission with nonce verification
- Database version control for updates

## Installation

1. Download the plugin files
2. Upload the plugin folder to the `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress

## Usage

### Adding the Form to Your Site

Use the shortcode `[easy_feedback_form]` in any post, page, or widget area where you want the feedback form to appear.

### Managing Submissions

1. Access the feedback submissions through the 'Easy Feedback' menu item in your WordPress admin panel
2. View all submissions in a list with status indicators
3. Click 'View' to see the full submission details
4. Delete submissions as needed

### Theme Integration

The plugin uses CSS variables for colors and styling, making it compatible with your theme's color scheme. The following variables are used:

- `--wp--preset--color--background`
- `--wp--preset--color--contrast`
- `--wp--preset--color--primary`
- `--wp--preset--color--primary-dark`

If your theme doesn't define these variables, the plugin will use fallback colors.

## Development

### File Structure

```
Easy-feedback-form/
├── assets/
|   |__images/
|   |   |__ Banner-772x250.png
|   |   |__ Banner-1544x500.png
|   |   |__ banner-icon-256x256.png
|   |   |__ banner-icon-128x128.png
|   |   |__ screenshot1.png
|   |   |__ screenshot2.png
|   |   |__ screenshot3.png
│   └── css/
│       ├── admin.css
│       └── frontend.css
├── includes/
│   ├── admin/
│   │   ├── views/
│   │   │   ├── list-submissions.php
│   │   │   └── single-submission.php
│   │   └── class-admin-page.php
│   ├── class-database.php
│   └── class-form-handler.php
├── README.md
|__ README.txt
└── Easy-feedback-form.php
```

### Version Control

The plugin includes database version control to handle updates smoothly. Current version: 1.1

## Security

- Input sanitization for all form fields
- Nonce verification for form submissions
- Capability checks for admin actions
- Prepared SQL statements for database queries

## Support

For support or feature requests, please create an issue in the repository.

## License

This plugin is licensed under GPL v2 or later. 