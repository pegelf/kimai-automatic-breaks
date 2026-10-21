<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Tests;

use App\Repository\TeamRepository;
use KimaiPlugin\AutomaticBreaksBundle\Calculation\Policy;
use KimaiPlugin\AutomaticBreaksBundle\Form\PolicyDataMapper;
use PHPUnit\Framework\TestCase;

final class PolicyDataMapperTest extends TestCase
{
    public function testFormRoundTripAndSortingKeepRuleModesAttached(): void
    {
        $mapper = new PolicyDataMapper($this->createMock(TeamRepository::class));
        $policy = new Policy(rules: [
            ['threshold' => 21600, 'break' => 1800, 'mode' => Policy::MODE_CAPPED],
            Policy::DEFAULT_RULES[1],
        ]);
        $form = $mapper->toForm($policy);
        self::assertSame(0, $form['maximumWork']);
        self::assertSame(360, $form['rules'][0]['threshold']);
        $form['rules'] = array_reverse($form['rules']);
        self::assertEquals($policy, $mapper->fromForm($form));
    }

    public function testMissingModesPreserveFixedDeductions(): void
    {
        $mapper = new PolicyDataMapper($this->createMock(TeamRepository::class));
        $form = $mapper->toForm(new Policy());
        unset($form['rules'][0]['mode']);
        self::assertSame(Policy::MODE_FIXED, $mapper->fromForm($form)->rules[0]['mode']);
        self::assertSame(Policy::MODE_CAPPED, $mapper->fromForm($form)->rules[1]['mode']);
    }

    public function testFractionalFormMinutesCannotBeTruncated(): void
    {
        $mapper = new PolicyDataMapper($this->createMock(TeamRepository::class));
        $form = $mapper->toForm(new Policy());
        $form['rules'][0]['threshold'] = 360.5;
        $this->expectException(\InvalidArgumentException::class);
        $mapper->fromForm($form);
    }

    public function testUnknownConfigurationKeysAreRejected(): void
    {
        $mapper = new PolicyDataMapper($this->createMock(TeamRepository::class));
        $form = $mapper->toForm(new Policy());
        $form['unknown'] = true;
        $this->expectException(\InvalidArgumentException::class);
        $mapper->fromForm($form);
    }
}
