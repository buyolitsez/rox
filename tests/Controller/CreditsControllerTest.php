<?php

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('integration')]
class CreditsControllerTest extends WebTestCase
{
    public function testCreditsThankZnuny(): void
    {
        $client = static::createClient();
        $client->request('GET', '/about/credits');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('dt a[href="https://www.znuny.org/"]', 'Znuny');
    }
}
