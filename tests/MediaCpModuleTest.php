<?php

use PHPUnit\Framework\TestCase;

final class MediaCpModuleTest extends TestCase
{
    public function testComposerPhpConstraintTargetsPhp74ThroughPhp85(): void
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);

        self::assertSame('>=7.4 <8.6', $composer['require']['php']);
    }

    public function testEnabledOptionRecognizesYesAndOneOnlyWhenPresent(): void
    {
        self::assertTrue(mediacp_isEnabledOption(['Stream Publishing' => 'yes'], 'Stream Publishing'));
        self::assertTrue(mediacp_isEnabledOption(['Stream Publishing' => 1], 'Stream Publishing'));
        self::assertFalse(mediacp_isEnabledOption(['Stream Publishing' => 'no'], 'Stream Publishing'));
        self::assertFalse(mediacp_isEnabledOption([], 'Stream Publishing'));
    }

    public function testUnitConversionNormalizesCommonStorageUnits(): void
    {
        self::assertSame(0, mediacp_ConvertUnitsToMegabyte(''));
        self::assertSame(999999, mediacp_ConvertUnitsToMegabyte('unlimited'));
        self::assertSame(512, mediacp_ConvertUnitsToMegabyte('512MB'));
        self::assertSame(2048, mediacp_ConvertUnitsToMegabyte('2GB'));
        self::assertSame(1048576, mediacp_ConvertUnitsToMegabyte('1TB'));
        self::assertEquals(1, mediacp_ConvertUnitsToMegabyte('1024KB'));
    }

    public function testClientDisplayNameEscapesApostrophesForApiPayload(): void
    {
        self::assertSame(
            "Luke O\\'Connor",
            mediacp_getClientDisplayName([
                'firstname' => ' Luke ',
                'lastname' => " O'Connor ",
            ])
        );
    }

    public function testProcessServiceOptionsDoesNotReadMissingStreamTargetOptions(): void
    {
        $args = [
            'plugin' => 'NginxRtmp',
            'sourceplugin' => 'No Source',
            'maxuser' => '100',
            'bitrate' => '128',
            'quota' => '500MB',
            'bandwidth' => '2TB',
            'customfields' => ['servicetype' => 'Live Streaming'],
        ];

        $processed = mediacp_ProcessServiceOptions($args, [
            'configoptions' => [
                'Facebook Publishing' => 'yes',
                'RTMP Publishing' => '0',
            ],
            'customfields' => [
                'Publish Name' => 'Station One',
                'Stream Name' => 'Main Feed',
            ],
        ]);

        self::assertSame('NginxRtmp', $processed['plugin']);
        self::assertSame(['Facebook'], $processed['customfields']['streamtargets']);
        self::assertSame('Station One', $processed['unique_id']);
        self::assertSame('Main Feed', $processed['customfields']['shoutcast_streamname']);
    }

    public function testLegacyIxrSubclassesConstructOnPhp8(): void
    {
        $multicall = new IXR_ClientMulticall('example.com', '/rpc.php', 80);
        self::assertInstanceOf(IXR_ClientMulticall::class, $multicall);

        $classServer = new IXR_ClassServer('.', true);
        self::assertInstanceOf(IXR_ClassServer::class, $classServer);
    }
}
