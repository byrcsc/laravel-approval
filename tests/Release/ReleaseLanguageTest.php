<?php

declare(strict_types=1);

/**
 * Keeps removed terminology and unsupported product guarantees out of the
 * shipped source, configuration, migration, and README.
 *
 * The rules are deliberately blunt: a failure here means either a genuine
 * regression or a new, intentional exception that belongs in the allowlist with
 * its reason written down.
 */

/**
 * @return array<string, string>
 */
function shippedFiles(): array
{
    $root = dirname(__DIR__, 2);
    $paths = [
        ...(glob($root.'/src/*.php') ?: []),
        ...(glob($root.'/src/*/*.php') ?: []),
        ...(glob($root.'/src/*/*/*.php') ?: []),
        ...(glob($root.'/config/*.php') ?: []),
        ...(glob($root.'/database/migrations/*.stub') ?: []),
        $root.'/README.md',
    ];

    $files = [];

    foreach ($paths as $path) {
        $files[substr($path, strlen($root) + 1)] = (string) file_get_contents($path);
    }

    return $files;
}

/**
 * @param  list<string>  $allowed  "path:needle" pairs that are intentional
 * @param  (callable(string): bool)|null  $only  limits the scan to matching paths
 * @return list<string>
 */
function offenders(string $pattern, array $allowed = [], ?callable $only = null): array
{
    $found = [];

    foreach (shippedFiles() as $path => $contents) {
        if ($only !== null && ! $only($path)) {
            continue;
        }

        foreach (explode("\n", $contents) as $number => $line) {
            if (preg_match($pattern, $line, $match) !== 1) {
                continue;
            }

            $needle = strtolower($match[0]);

            foreach ($allowed as $exception) {
                [$allowedPath, $allowedNeedle] = explode(':', $exception, 2);

                if ($path === $allowedPath && str_contains($needle, $allowedNeedle)) {
                    continue 2;
                }
            }

            $found[] = $path.':'.($number + 1).' '.trim($line);
        }
    }

    return $found;
}

it('keeps election and parliamentary terminology out of the shipped package', function (): void {
    expect(offenders('/\b(ballots?|quorums?|carried|seats?|slots?|votes?|voters?|voting)\b/i'))->toBe([]);
});

it('keeps the superseded stage vocabulary out of the shipped package', function (): void {
    // No allowlist: the last exception, the audit hash payload's `step` key,
    // was renamed to `stage` once a fresh migration was accepted.
    expect(offenders(
        // The lookbehind lets through compounds such as record-level and
        // application-level, which are not the stage vocabulary.
        '/(?<![-\w])(levels?|steps?)\b/i',
    ))->toBe([]);
});

it('keeps the superseded submitter vocabulary out of the shipped package', function (): void {
    // The verb stays - submitForApproval(), submitted_at, ApprovalSubmitted -
    // because it names the act. Only the noun for the person is `requester`.
    expect(offenders('/[Ss]ubmitter/'))->toBe([]);
});

it('does not claim the audit history is immutable or tamper-proof', function (): void {
    // Absent entirely from code, configuration, and the migration.
    expect(offenders(
        '/\b(immutable|immutability|tamper[- ]?proof|tamper[- ]?resistant|unalterable|write[- ]?once)\b/i',
        only: fn (string $path): bool => $path !== 'README.md',
    ))->toBe([]);

    // The README may name these terms only to deny them, so it is checked for
    // the claim rather than for the words.
    expect(offenders(
        '/\b(immutable|tamper[- ]?proof|unalterable)\s+(audit|history|trail|log|actions?|record)/i',
        only: fn (string $path): bool => $path === 'README.md',
    ))->toBe([]);
});

it('does not claim the package gates persistence, publication, or visibility', function (): void {
    expect(offenders('/\b(gates? (creation|persistence|publication|publishing|visibility)|prevents? (creation|persistence)|before (records|the record) (are|is) (created|saved|persisted))\b/i'))
        ->toBe([]);
});

it('does not claim container models expand into their members', function (): void {
    expect(offenders('/\b(expand(s|ed|ing)? (a |the )?(team|role|position|group)|every (user|member) (in|of) (that|the) (team|role|group))\b/i'))
        ->toBe([]);
});

it('avoids editorial and anthropomorphic framing in shipped prose', function (): void {
    expect(offenders('/\b(rubbish|honest default|a package you uninstall|gets uninstalled|is a lie|would be a lie|helps nobody|mid-thought|god service)\b/i'))
        ->toBe([]);
});

it('describes the audit history in the documented terms', function (): void {
    $readme = shippedFiles()['README.md'];

    expect($readme)->toContain('application-enforced append-only')
        ->and($readme)->toContain('tamper-evident')
        ->and($readme)->toContain('not immutable')
        ->and($readme)->toContain('bytes are never read');
});

it('documents record-level approval and attribute drafts truthfully', function (): void {
    $readme = shippedFiles()['README.md'];

    expect($readme)->toContain('already-persisted record')
        ->and($readme)->toContain('built-in hold-until-approved mechanism')
        ->and($readme)->toContain('does not hide, publish, activate,');
});

it('does not offer a compatibility or deprecation layer for the unpublished API', function (): void {
    expect(offenders('/\b(@deprecated|deprecation period|backwards[- ]compat|backward[- ]compat|compatibility alias)\b/i'))
        ->toBe([]);
});
