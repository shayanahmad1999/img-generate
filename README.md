# Text to Image Generator

A small PHP and HTML app that can generate images with Google Gemini or OpenAI.

## Requirements

- XAMPP with Apache and PHP 8.1 or newer
- PHP cURL extension enabled
- A Gemini API key with access to an image-generation model, or an OpenAI API key with image-generation access

## Setup

1. Put this project in `htdocs` and start Apache from the XAMPP Control Panel.
2. Copy `.env.example` to `.env` in the project root, then replace the placeholder for the provider you use:

   ```env
   GEMINI_API_KEY=your-gemini-api-key
   GEMINI_IMAGE_MODEL=gemini-2.5-flash-image
   ```

   To use OpenAI instead, replace `OPENAI_API_KEY` with your key and select OpenAI in the page.

3. Open `http://localhost/img-generate/` in your browser. Gemini is selected by default.

The PHP endpoint reads keys from server environment variables first, then from the local project-root `.env` file. Gemini uses an image-capable model; the default is `gemini-2.5-flash-image`. Current Gemini image models include `gemini-3.1-flash-image` and `gemini-3-pro-image`; a text-only Gemini model will not return generated images. The `.env` file is excluded by `.gitignore` and blocked from direct Apache access; do not commit or publish it. For a public deployment, configure keys as server environment variables and keep any `.env` file outside the web document root.

## Troubleshooting

- If cURL is unavailable, enable `extension=curl` in the active `php.ini` and restart Apache.
- If image generation is not configured, verify the `.env` filename, location, and key for the selected provider (`GEMINI_API_KEY` or `OPENAI_API_KEY`).
- API failures are logged in the PHP/Apache error log; the endpoint intentionally avoids returning upstream response details to the browser.

## Security note

Never place an API key in HTML or JavaScript. If a key has previously been pasted into source code, shared, or committed, revoke it and create a replacement before using the app again.