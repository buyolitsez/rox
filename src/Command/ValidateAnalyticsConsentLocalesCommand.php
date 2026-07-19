<?php

namespace App\Command;

use App\Service\AnalyticsPageClassifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'analytics:validate-consent-locales',
    description: 'Verify that optional analytics consent text has active exact translations for every supported locale.',
)]
class ValidateAnalyticsConsentLocalesCommand extends Command
{
    /** @param string[] $locales */
    public function __construct(
        private readonly AnalyticsPageClassifier $pageClassifier,
        private readonly array $locales,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $invalidLocales = [];

        foreach ($this->locales as $locale) {
            $missing = $this->pageClassifier->getMissingConsentTranslations($locale);
            if ([] !== $missing) {
                $invalidLocales[$locale] = $missing;
            }
        }

        if ([] === $invalidLocales) {
            $io->success('Exact analytics consent translations are active for every supported locale.');

            return Command::SUCCESS;
        }

        foreach ($invalidLocales as $locale => $missing) {
            $io->error(\sprintf(
                'Locale "%s" is missing active exact translations: %s',
                $locale,
                implode(', ', $missing),
            ));
        }

        $io->warning('Keep GOOGLE_ANALYTICS_MEASUREMENT_ID blank until this command succeeds.');

        return Command::FAILURE;
    }
}
