<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\Migration;

final class SourcePatternRewriter
{
    /** @var array<string, string> */
    private const IMPORT_REWRITES = [
        'use Pecee\\Pixie\\Connection;' => 'use Oeltima\\SimpleQuery\\Connection;',
    ];

    public function analyze(string $source): RewriteDecision
    {
        $trimmed = trim($source);
        if (isset(self::IMPORT_REWRITES[$trimmed])) {
            return new RewriteDecision(true, 'import_only', self::IMPORT_REWRITES[$trimmed]);
        }

        if (preg_match('/->updateOrInsert\s*\(/', $source) === 1) {
            return new RewriteDecision(false, 'unsupported_method');
        }
        if (preg_match('/->insert\s*\(/', $source) === 1) {
            return new RewriteDecision(false, 'insert_return_semantics');
        }
        if (str_contains($source, 'getLastQuery') || str_contains($source, 'getRawSql')) {
            return new RewriteDecision(false, 'diagnostic_semantics');
        }
        if (
            preg_match('/->(?:query|raw)\s*\([^\n]*(?:\.[ \t]*\$|\{\$|"[^"]*\$)/', $source) === 1
        ) {
            return new RewriteDecision(false, 'raw_sql_security');
        }
        if (preg_match('/["\'][^"\'\n]*:[A-Za-z_][A-Za-z0-9_]*/', $source) === 1) {
            return new RewriteDecision(false, 'named_placeholder_contract');
        }
        if (
            str_contains($source, 'transaction(')
            && preg_match(
                '/(?:->(?:pdo|getPdoInstance)\(\)|\$transaction)->(?:commit|rollBack)\s*\(/',
                $source,
            ) === 1
        ) {
            return new RewriteDecision(false, 'transaction_ownership');
        }
        if (str_contains($source, 'Pecee\\Pixie\\')) {
            return new RewriteDecision(false, 'construction_context');
        }

        return new RewriteDecision(false, 'manual_review');
    }
}
