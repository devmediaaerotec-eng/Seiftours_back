<?php
namespace App\EventSubscriber;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class LoginSubscriber implements EventSubscriberInterface
{
    public function __construct(private Connection $db) {}

    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'onLoginSuccess'];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if ($event->getFirewallName() !== 'admin_login') {
            return;
        }
        $this->db->executeStatement(
            'UPDATE staff_users SET last_login_at = UTC_TIMESTAMP(3) WHERE email = ?',
            [$event->getUser()->getUserIdentifier()]
        );
    }
}