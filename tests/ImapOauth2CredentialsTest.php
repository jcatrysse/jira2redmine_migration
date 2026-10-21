<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B92: 5.1-stable-GEOxyz fetches mail with receive_imap_oauth2 and two files per mailbox (the token with Ruby symbol
 * keys, the application with relative Microsoft endpoints); 7.0-stable-GEOxyz has receive_imap with one credentials
 * file of string keys, read with YAML.safe_load_file. tools/imap_oauth2_credentials.rb writes that file. The fixtures
 * are what the oauth2 gem writes for 5.1 (OAuth2::AccessToken#to_hash.to_yaml), every value a dummy.
 */
final class ImapOauth2CredentialsTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/imap_oauth2';
    private const TENANT = '00000000-0000-0000-0000-00000000tenant';

    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/j2r-imap-oauth2-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/config', 0700, true);
        mkdir($this->directory . '/etc', 0700);
        foreach (array_diff((array)scandir(self::FIXTURES), ['.', '..']) as $file) {
            copy(self::FIXTURES . '/' . $file, $this->directory . '/config/' . $file);
        }
    }

    protected function tearDown(): void
    {
        foreach ([$this->directory . '/config', $this->directory . '/etc'] as $directory) {
            foreach (array_diff((array)scandir($directory), ['.', '..']) as $file) {
                unlink($directory . '/' . $file);
            }
            rmdir($directory);
        }
        rmdir($this->directory);
    }

    public function testThe51FilesBecomeOneCredentialsFileThat70Reads(): void
    {
        [$status, $output, $errors] = $this->convert();

        self::assertSame(0, $status, $errors);
        $target = $this->directory . '/etc/support.yml';
        self::assertSame(0600, fileperms($target) & 0777);
        self::assertSame([
            'token_url' => 'https://login.microsoftonline.com/' . self::TENANT . '/oauth2/v2.0/token',
            'client_id' => 'dummy-client-id',
            'client_secret' => 'dummy-client-secret',
            'refresh_token' => 'dummy-refresh-token',
            'scope' => 'https://outlook.office.com/IMAP.AccessAsUser.All offline_access',
            'authorize_url' => 'https://login.microsoftonline.com/' . self::TENANT . '/oauth2/v2.0/authorize',
            'redirect_uri' => 'http://localhost/',
        ], $this->readAs70($target));
        // it says what it wrote, never a value of it
        self::assertStringContainsString('token_url, client_id, client_secret, refresh_token, scope', $output);
        // not 5.1's wider consent (POP, SMTP, User.Read), which the token file names
        self::assertStringContainsString(
            'provider: Microsoft 365; scope: https://outlook.office.com/IMAP.AccessAsUser.All offline_access (IMAP only)',
            $output
        );
        foreach (['dummy-client-secret', 'dummy-refresh-token', 'dummy-access-token', 'dummy-client-id'] as $value) {
            self::assertStringNotContainsString($value, $output . $errors);
        }
        self::assertSame([], (array)glob($this->directory . '/etc/*.tmp'));
    }

    public function testThe51TokenFileItselfIsUnreadableFor70(): void
    {
        // why the conversion exists: 7.0 reads string keys only, 5.1 writes ":refresh_token:"
        [$status, , $errors] = $this->execute([
            'ruby', '-ryaml', '-e', 'YAML.safe_load_file(ARGV[0])', $this->directory . '/config/email_oauth2_support.yml',
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString('Psych::DisallowedClass', $errors);
    }

    public function testAPublicClientStops(): void
    {
        // 5.1's O365 guide says a public app may omit the secret; 7.0 sends client_secret in every token request
        $this->edit('email_oauth2_support_client.yml', "client_secret: dummy-client-secret\n", '');

        [$status, $output, $errors] = $this->convert();

        self::assertSame(1, $status);
        self::assertStringContainsString('holds no client_secret. 7.0 sends a client secret', $errors);
        self::assertStringContainsString('authorize the mailbox again', $errors);
        self::assertSame('', $output);
        self::assertFileDoesNotExist($this->directory . '/etc/support.yml');
    }

    public function testAnExistingCredentialsFileIsNeverReplaced(): void
    {
        $target = $this->directory . '/etc/support.yml';
        file_put_contents($target, "refresh_token: the-newer-one\n");

        [$status, , $errors] = $this->convert();

        self::assertSame(1, $status);
        self::assertStringContainsString('exists; it is never replaced', $errors);
        self::assertSame("refresh_token: the-newer-one\n", file_get_contents($target));
    }

    public function testATokenWithoutRefreshTokenStops(): void
    {
        $this->edit('email_oauth2_support.yml', ":refresh_token: dummy-refresh-token\n", '');

        [$status, , $errors] = $this->convert();

        self::assertSame(1, $status);
        self::assertStringContainsString('holds no refresh_token', $errors);
        self::assertFileDoesNotExist($this->directory . '/etc/support.yml');
    }

    public function testAConsentWithoutImapStops(): void
    {
        // a refresh request may only ask for scopes the mailbox owner granted
        $this->edit('email_oauth2_support.yml', 'https://outlook.office.com/IMAP.AccessAsUser.All ', '');

        [$status, , $errors] = $this->convert();

        self::assertSame(1, $status);
        self::assertStringContainsString('does not include https://outlook.office.com/IMAP.AccessAsUser.All', $errors);
        self::assertFileDoesNotExist($this->directory . '/etc/support.yml');
    }

    public function testTheTokenFileValueOfThe51CronLineIsReadAs51ReadsIt(): void
    {
        // 5.1's normalize_token_file: token_file=config/support means config/email_oauth2_support.yml
        [$status, , $errors] = $this->execute([
            'ruby', __DIR__ . '/../tools/imap_oauth2_credentials.rb',
            $this->directory . '/config/support', $this->directory . '/etc/support.yml',
        ]);

        self::assertSame(0, $status, $errors);
        self::assertSame('dummy-refresh-token', $this->readAs70($this->directory . '/etc/support.yml')['refresh_token']);
    }

    public function testTheTokenFileNamedEmailOauth2Alone(): void
    {
        // 5.1's own default name, as on Jan's copy: config/email_oauth2.yml and config/email_oauth2_client.yml
        rename($this->directory . '/config/email_oauth2_support.yml', $this->directory . '/config/email_oauth2.yml');
        rename($this->directory . '/config/email_oauth2_support_client.yml', $this->directory . '/config/email_oauth2_client.yml');

        [$status, , $errors] = $this->convert(null, $this->directory . '/config/email_oauth2');

        self::assertSame(0, $status, $errors);
        self::assertSame('dummy-refresh-token', $this->readAs70($this->directory . '/etc/support.yml')['refresh_token']);
    }

    public function testAGmailMailboxBecomesTheFileOfTheCoreGuide(): void
    {
        [$status, $output, $errors] = $this->convert(null, $this->directory . '/config/email_oauth2_gmail');

        self::assertSame(0, $status, $errors);
        self::assertSame(0600, fileperms($this->directory . '/etc/support.yml') & 0777);
        // core doc/IMAP_OAUTH2.md, "Gmail and Google Workspace"
        self::assertSame([
            'token_url' => 'https://oauth2.googleapis.com/token',
            'client_id' => 'dummy-gmail-client-id.apps.googleusercontent.com',
            'client_secret' => 'dummy-gmail-client-secret',
            'refresh_token' => 'dummy-gmail-refresh-token',
            'scope' => 'https://mail.google.com/',
            'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'redirect_uri' => 'http://localhost',
            'authorize_params' => ['access_type' => 'offline', 'prompt' => 'consent'],
        ], $this->readAs70($this->directory . '/etc/support.yml'));
        self::assertStringContainsString('provider: Google; scope: https://mail.google.com/ (from the token file)', $output);
        self::assertStringContainsString('expire after 7 days', $output);
        foreach (['dummy-gmail-client-secret', 'dummy-gmail-refresh-token', 'dummy-gmail-access-token'] as $value) {
            self::assertStringNotContainsString($value, $output . $errors);
        }
    }

    public function testAGmailTokenWithoutScopeGetsGooglesScope(): void
    {
        $this->edit('email_oauth2_gmail.yml', ":scope: https://mail.google.com/\n", '');

        [$status, $output, $errors] = $this->convert(null, $this->directory . '/config/email_oauth2_gmail');

        self::assertSame(0, $status, $errors);
        self::assertSame('https://mail.google.com/', $this->readAs70($this->directory . '/etc/support.yml')['scope']);
        self::assertStringContainsString("scope: https://mail.google.com/ (Google's)", $output);
    }

    public function testAGmailConsentWithoutMailStops(): void
    {
        $this->edit('email_oauth2_gmail.yml', ':scope: https://mail.google.com/', ':scope: https://www.googleapis.com/auth/gmail.readonly');

        [$status, , $errors] = $this->convert(null, $this->directory . '/config/email_oauth2_gmail');

        self::assertSame(1, $status);
        self::assertStringContainsString('does not include https://mail.google.com/', $errors);
        self::assertFileDoesNotExist($this->directory . '/etc/support.yml');
    }

    public function testATargetDirectoryThatDoesNotExistStops(): void
    {
        [$status, , $errors] = $this->convert($this->directory . '/missing/support.yml');

        self::assertSame(1, $status);
        self::assertStringContainsString('does not exist', $errors);
    }

    /**
     * @return array{int, string, string}
     */
    private function convert(?string $target = null, ?string $tokenFile = null): array
    {
        return $this->execute([
            'ruby', __DIR__ . '/../tools/imap_oauth2_credentials.rb',
            $tokenFile ?? $this->directory . '/config/email_oauth2_support', $target ?? $this->directory . '/etc/support.yml',
        ]);
    }

    /**
     * The file as 7.0's Redmine::Oauth2Client#read_credentials loads it: YAML.safe_load_file, no permitted classes.
     *
     * @return array<string, mixed>
     */
    private function readAs70(string $file): array
    {
        [$status, $output, $errors] = $this->execute([
            'ruby', '-ryaml', '-rjson', '-e', 'puts JSON.generate(YAML.safe_load_file(ARGV[0]))', $file,
        ]);
        self::assertSame(0, $status, $errors);
        $credentials = json_decode($output, true, 4, JSON_THROW_ON_ERROR);
        self::assertIsArray($credentials);

        return $credentials;
    }

    private function edit(string $file, string $search, string $replace): void
    {
        $path = $this->directory . '/config/' . $file;
        $content = (string)file_get_contents($path);
        self::assertStringContainsString($search, $content);
        file_put_contents($path, str_replace($search, $replace, $content));
    }

    /**
     * @param list<string> $command
     * @return array{int, string, string}
     */
    private function execute(array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process, 'ruby is needed for this test (the Redmine host has it)');
        $output = (string)stream_get_contents($pipes[1]);
        $errors = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $errors];
    }
}
