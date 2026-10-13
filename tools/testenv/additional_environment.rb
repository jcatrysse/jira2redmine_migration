# Test env: X-Sendfile only where the web server handles it (Apache sets REDMINE_X_SENDFILE_HEADER).
config.action_dispatch.x_sendfile_header = ENV['REDMINE_X_SENDFILE_HEADER'] if ENV['REDMINE_X_SENDFILE_HEADER'].to_s != ''
