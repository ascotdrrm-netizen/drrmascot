<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The project-root .htaccess is a deny-only safety net for a host that serves
 * the project folder instead of public/. These tests make sure nobody quietly
 * drops a folder or file from it, and that it never starts rewriting requests
 * into public/ (which would make a wrongly rooted deploy look like it works).
 */
class RootHtaccessTest extends TestCase
{
    protected const FOLDERS = ['app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'storage', 'tests', 'vendor'];

    protected const FILES = ['composer\.json', 'composer\.lock', 'artisan', 'phpunit\.xml'];

    protected function htaccess(): string
    {
        $path = base_path('.htaccess');
        $this->assertFileExists($path, 'the project-root .htaccess safety net is missing');

        return file_get_contents($path);
    }

    public function test_every_non_public_folder_is_denied_with_and_without_mod_rewrite(): void
    {
        $htaccess = $this->htaccess();

        $this->assertSame(1, preg_match('/^\s*RewriteRule \^\(([a-z|]+)\)\(\/\|\$\) - \[F,L\]\s*$/m', $htaccess, $rewrite), 'no deny rule for the folders (mod_rewrite)');
        $this->assertSame(1, preg_match('/^\s*RedirectMatch 403 \(\^\|\/\)\(([a-z|]+)\)\(\/\|\$\)\s*$/m', $htaccess, $fallback), 'no deny rule for the folders (fallback without mod_rewrite)');

        foreach ([$rewrite[1], $fallback[1]] as $list) {
            $denied = explode('|', $list);
            foreach (self::FOLDERS as $folder) {
                $this->assertContains($folder, $denied, "$folder/ is not denied");
            }
            $this->assertNotContains('public', $denied, 'public/ must not be denied');
        }
    }

    public function test_dotfiles_and_dot_directories_are_denied(): void
    {
        $htaccess = $this->htaccess();

        // .env, .env.example, .gitignore … at any depth
        $this->assertSame(1, preg_match('/<FilesMatch "\^\(([^"]+)\)\$">/', $htaccess, $files), 'no FilesMatch deny block');
        $this->assertContains('\..*', explode('|', $files[1]), 'dotfiles (.env) are not denied');
        // .git/, .github/ … as directories, with and without mod_rewrite
        $this->assertMatchesRegularExpression('/^\s*RewriteRule \(\^\|\/\)\\\\\. - \[F,L\]\s*$/m', $htaccess, 'dot-directories (.git) are not denied (mod_rewrite)');
        $this->assertMatchesRegularExpression('/^\s*RedirectMatch 403 \(\^\|\/\)\\\\\.\s*$/m', $htaccess, 'dot-directories (.git) are not denied (fallback)');
    }

    public function test_the_projects_own_files_are_denied(): void
    {
        $this->assertSame(1, preg_match('/<FilesMatch "\^\(([^"]+)\)\$">(.*?)<\/FilesMatch>/s', $this->htaccess(), $block));
        $denied = explode('|', $block[1]);
        foreach (self::FILES as $file) {
            $this->assertContains($file, $denied, stripslashes($file) . ' is not denied');
        }
        // Denied on Apache 2.4 and on 2.2.
        $this->assertStringContainsString('Require all denied', $block[2]);
        $this->assertStringContainsString('Deny from all', $block[2]);
    }

    public function test_it_only_denies_and_never_rewrites_into_public(): void
    {
        $htaccess = $this->htaccess();

        preg_match_all('/^\s*RewriteRule\s+(\S+)\s+(\S+)\s+(\S+)/m', $htaccess, $rules, PREG_SET_ORDER);
        $this->assertNotEmpty($rules);
        foreach ($rules as [$line, $pattern, $substitution, $flags]) {
            $this->assertSame('-', $substitution, "a rule rewrites somewhere: $line");
            $this->assertStringContainsString('F', $flags, "a rule doesn't forbid: $line");
        }
        foreach (['public/', 'index.php', 'RewriteBase', 'DirectoryIndex', 'FallbackResource'] as $redirection) {
            $this->assertStringNotContainsString($redirection, preg_replace('/^\s*#.*$/m', '', $htaccess), "the safety net must not route requests ($redirection)");
        }
        $this->assertMatchesRegularExpression('/^\s*Options -Indexes\s*$/m', $htaccess, 'directory listings must be off');
    }
}
