# Profile image upload storage

`user_registration_uploads/profile-pictures` and `temp-uploads` supply public
profile images and image previews. Their image URLs are not access-controlled.
Do not use these directories for identity documents or other confidential data.

The plugin creates an empty index, Apache `.htaccess`, and IIS `web.config`
on installation/update and when preparing upload directories. The server rules
disable directory listings and allow JPEG, PNG, and GIF images while denying
other file types. Apache also rejects script-like intermediate extensions.
Existing server configuration files are preserved. Temporary-file cleanup keeps
these protection files and removes expired uploads when cleanup runs.

These files are defense in depth. Apache must honor `.htaccess`, IIS must allow
the configuration sections, and **Nginx does not use either file**. Nginx hosts
must configure this upload URL subtree to serve supported images only as static
files, with directory listing and script execution disabled. Verify a real
image returns 200 and non-image/script requests return 403 or 404 on the target
server. Custom profile-directory overrides are not modified because they may share storage
with unrelated uploads; their owners must configure an appropriate policy. The
`user_registration_install_skip_create_files` opt-out is honored.

For private files, use storage outside the public web root and a download handler
that checks the requesting user's authorization. A blanket deny on these profile
image directories breaks the plugin's current image and preview URLs. This
hardening does not make known image URLs private and does not resolve that
separate product requirement in issue #1693.

Regression test: `php tests/security/upload-directories.php`. This checks generated
rules, public-image compatibility, preservation of custom rules and cleanup. It
does not replace testing the effective Apache/IIS/Nginx configuration.
