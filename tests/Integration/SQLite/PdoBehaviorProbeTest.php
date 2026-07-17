<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Integration\SQLite;

use Oeltima\SimpleQuery\Tools\DatabaseProbe\PdoBehaviorProbe;
use Oeltima\SimpleQuery\Tools\DatabaseProbe\ProbeTarget;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class PdoBehaviorProbeTest extends TestCase
{
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testSQLiteProbeProducesCompleteRedactedEvidence(): void
    {
        $target = ProbeTarget::named('sqlite');
        $report = (new PdoBehaviorProbe($target, $target->connect()))->run();
        $data = $report->jsonSerialize();
        $runtime = $data['runtime'] ?? null;
        $observations = $data['observations'] ?? null;
        self::assertIsArray($runtime);
        self::assertIsArray($observations);

        self::assertFalse($report->hasFailures(), json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        self::assertSame('sqlite', $data['target'] ?? null);
        self::assertSame('sqlite', $data['engine'] ?? null);
        self::assertSame('sqlite', $runtime['pdo_driver'] ?? null);
        self::assertCount(11, $observations);
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testSQLiteFileFixtureCoversIntegrityContentionAndTypes(): void
    {
        $target = ProbeTarget::named('sqlite');
        $report = (new PdoBehaviorProbe($target, $target->connect()))->run()->jsonSerialize();
        $observations = $report['observations'] ?? [];
        self::assertIsArray($observations);

        $fileProbe = null;
        foreach ($observations as $observation) {
            if (is_array($observation) && ($observation['name'] ?? null) === 'sqlite_file_backed') {
                $fileProbe = $observation['details'] ?? null;
            }
        }

        self::assertIsArray($fileProbe);
        self::assertSame('wal', $fileProbe['journal_mode'] ?? null);
        self::assertSame(1, $fileProbe['foreign_keys_first_connection'] ?? null);
        self::assertSame(0, $fileProbe['foreign_keys_second_connection'] ?? null);
        self::assertTrue($fileProbe['strict_table_rejected_text'] ?? false);
        self::assertTrue($fileProbe['writer_serialization_observed'] ?? false);
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testLiteralQuestionMarksDoNotConsumeBindings(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $statement = $pdo->prepare("SELECT '?' AS literal_question, ? AS bound_value /* ? */");
        $statement->execute(['bound']);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame('?', $row['literal_question'] ?? null);
        self::assertSame('bound', $row['bound_value'] ?? null);
    }
}
