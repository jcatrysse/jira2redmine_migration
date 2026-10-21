#!/usr/bin/env ruby
# frozen_string_literal: true

# B92 (docs/PRODUCTION.md A.4 step 4b): the OAuth credentials of one mailbox, from 5.1 to 7.0.
#
# 5.1-stable-GEOxyz fetches mail with `rake redmine:email:receive_imap_oauth2 token_file=BASE`, which keeps two files:
# BASE.yml, the token (Ruby symbol keys, rewritten on every refresh), and BASE_client.yml, the application. 7.0-stable-
# GEOxyz has no such task: `rake redmine:email:receive_imap oauth2_credentials=FILE` reads one YAML file with string
# keys (lib/redmine/oauth2_client.rb): token_url, client_id, client_secret, refresh_token and scope, plus
# authorize_url, redirect_uri and authorize_params for `redmine:email:oauth2_authorize`. This writes that file, for a
# Microsoft 365 and for a Gmail mailbox (5.1's o365_oauth2_init and google_oauth2_init).
#
#   ruby tools/imap_oauth2_credentials.rb /path/to/5.1/config/email_oauth2_NAME /etc/redmine/imap_oauth2/NAME.yml
#
# The first argument is the token_file value of the mailbox's 5.1 cron line, made absolute against the 5.1 root, read
# as 5.1 reads it: a name without the email_oauth2 prefix gets it (token_file=config/support reads
# config/email_oauth2_support.yml and config/email_oauth2_support_client.yml).
# Run it as the Redmine user, after 5.1's crontab is stopped: BASE.yml must be the token as 5.1 last wrote it. It
# prints no secret (the keys it wrote, the provider and the scopes), writes the new file with mode 0600 and never
# replaces an existing one.

require 'securerandom'
require 'uri'
require 'yaml'

MICROSOFT_IMAP = 'https://outlook.office.com/IMAP.AccessAsUser.All'
GOOGLE_IMAP = 'https://mail.google.com/'
# By the host of token_url: the provider and the scope IMAP needs. 5.1 writes both (o365_oauth2_init, google_oauth2_init).
PROVIDERS = {
  'login.microsoftonline.com' => ['Microsoft 365', MICROSOFT_IMAP],
  'oauth2.googleapis.com' => ['Google', GOOGLE_IMAP],
  'accounts.google.com' => ['Google', GOOGLE_IMAP]
}.freeze

def stop(message)
  warn "imap_oauth2_credentials: #{message}"
  exit 1
end

# 5.1 writes symbol keys (":refresh_token:") and symbol values (":mode: :header"); 7.0 reads string keys only.
def read_yaml(path)
  stop "#{path} not found" unless File.file?(path)
  data = YAML.safe_load_file(path, permitted_classes: [Symbol], aliases: true)
  stop "#{path} is not a YAML mapping" unless data.is_a?(Hash)
  data.to_h { |key, value| [key.to_s, value] }
rescue Psych::Exception => e
  stop "#{path} cannot be read as YAML (#{e.class})"
end

# 5.1's normalize_token_file, exactly: token_file=config/x.yml reads config/email_oauth2_x.yml.yml there too.
def token_file_base(path)
  name = File.basename(path)
  name.start_with?('email_oauth2') ? path : File.join(File.dirname(path), "email_oauth2_#{name}")
end

def present(value)
  value.is_a?(String) && !value.strip.empty? ? value.strip : nil
end

# 5.1 keeps the endpoints relative to `site` for Microsoft ("/TENANT/oauth2/v2.0/token"); 7.0 needs https URLs.
def absolute_url(value, site, name)
  uri = URI.parse(value)
  uri = URI.join(site.to_s, value) if uri.relative?
  stop "#{name} is not an https URL" unless uri.is_a?(URI::HTTPS)
  uri.to_s
rescue URI::Error
  stop "#{name} is not a URL"
end

stop 'usage: ruby tools/imap_oauth2_credentials.rb 5.1-TOKEN-FILE NEW-CREDENTIALS-FILE' unless ARGV.size == 2
base = token_file_base(ARGV[0])
target = ARGV[1]
stop "#{target} exists; it is never replaced" if File.exist?(target)
stop "the directory of #{target} does not exist" unless File.directory?(File.dirname(target))

token = read_yaml("#{base}.yml")
client = read_yaml("#{base}_client.yml")

refresh_token = present(token['refresh_token'])
stop "#{base}.yml holds no refresh_token: authorize the mailbox again (doc/IMAP_OAUTH2.md)" unless refresh_token
client_id = present(client['client_id'])
stop "#{base}_client.yml holds no client_id" unless client_id
client_secret = present(client['client_secret'])
unless client_secret
  # 5.1's init tasks require one; its Microsoft 365 guide says a public app may omit it
  stop "#{base}_client.yml holds no client_secret. 7.0 sends a client secret with every token request: give the " \
       'application one and authorize the mailbox again (doc/IMAP_OAUTH2.md)'
end
stop "#{base}_client.yml holds no token_url" unless present(client['token_url'])

token_url = absolute_url(client['token_url'], client['site'], 'token_url')
host = URI.parse(token_url).host
provider, imap_scope = PROVIDERS.fetch(host, [host, nil])

# What the mailbox owner consented to, as the token response named it; the refresh request asks for a subset only.
granted = present(token['scope'])&.split || []
if imap_scope && granted.any? && granted.none? { |granted_scope| granted_scope.casecmp?(imap_scope) }
  stop "the consent of #{base}.yml does not include #{imap_scope}: authorize the mailbox again"
end

# Microsoft 365: IMAP and offline_access only (core doc/IMAP_OAUTH2.md), not 5.1's wider consent (POP, SMTP, User.Read).
# Otherwise the scope the token file names, else the provider's (Google: offline is access_type, not a scope).
scope, source = if provider == 'Microsoft 365'
                  ["#{MICROSOFT_IMAP} offline_access", 'IMAP only']
                elsif !granted.empty?
                  [granted.join(' '), 'from the token file']
                elsif imap_scope
                  [imap_scope, "#{provider}'s"]
                else
                  [present(client['scope']), 'from the client file']
                end
stop "#{base}.yml and #{base}_client.yml hold no scope for #{host}" unless scope

credentials = {
  'token_url' => token_url,
  'client_id' => client_id,
  'client_secret' => client_secret,
  'refresh_token' => refresh_token,
  'scope' => scope
}
if present(client['authorize_url'])
  credentials['authorize_url'] = absolute_url(client['authorize_url'], client['site'], 'authorize_url')
end
credentials['redirect_uri'] = present(client['redirect_uri']) if present(client['redirect_uri'])
authorize_params = client['auth_params'].is_a?(Hash) ? client['auth_params'].to_h { |key, value| [key.to_s, value] } : {}
# Google returns a refresh token on the first consent only: a new consent (oauth2_authorize) needs prompt=consent too
authorize_params = { 'access_type' => 'offline', 'prompt' => 'consent' }.merge(authorize_params) if provider == 'Google'
credentials['authorize_params'] = authorize_params unless authorize_params.empty?

# 7.0 rewrites the refresh_token line when the provider rotates the token (core 3a71d578b, store_refresh_token), and
# only a single top-level line: never folded, whatever the token's length.
yaml = credentials.to_yaml(line_width: -1)
# Written beside its final name, then linked: a reader never sees half a file, and an existing file stays.
temporary = "#{target}.#{SecureRandom.hex(8)}.tmp"
File.open(temporary, File::WRONLY | File::CREAT | File::EXCL, 0o600) { |file| file.write(yaml) }
begin
  File.chmod(0o600, temporary)
  File.link(temporary, target)
rescue Errno::EEXIST
  stop "#{target} exists; it is never replaced"
ensure
  File.unlink(temporary)
end

puts "#{target}: #{credentials.keys.join(', ')} (mode 600)"
puts "provider: #{provider}; scope: #{scope} (#{source})"
puts "granted by the mailbox owner: #{granted.empty? ? '(not recorded in the token file)' : granted.join(' ')}"
if provider == 'Google'
  puts 'Google: an app whose OAuth consent screen is External and left in Testing gets refresh tokens that expire ' \
       'after 7 days; publish it (or use Internal for a Workspace domain)'
end
