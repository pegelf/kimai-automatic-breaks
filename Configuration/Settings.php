<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Configuration;

use App\Configuration\ConfigurationService;
use App\Entity\Configuration;
use KimaiPlugin\AutomaticBreaksBundle\Calculation\Policy;

final class Settings
{
    public const KEY = 'automatic_breaks.policy';

    public function __construct(private readonly ConfigurationService $configuration)
    {
    }

    public function getPolicy(): Policy
    {
        $value = $this->configuration->getConfiguration(self::KEY)?->getValue();
        if ($value === null || $value === '') {
            return new Policy();
        }

        try {
            $data = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

            if (!\is_array($data)) {
                throw new \InvalidArgumentException('Automatic break settings must be an object.');
            }

            return Policy::fromArray($data);
        } catch (\Throwable $exception) {
            // Invalid settings must not silently introduce deductions with fallback values.
            throw new \UnexpectedValueException('Invalid stored automatic break settings.', 0, $exception);
        }
    }

    public function save(Policy $policy): void
    {
        $entity = $this->configuration->getConfiguration(self::KEY) ?? new Configuration();
        $entity->setName(self::KEY);
        $entity->setValue(json_encode(get_object_vars($policy), JSON_THROW_ON_ERROR));
        $this->configuration->saveConfiguration($entity);
    }
}
