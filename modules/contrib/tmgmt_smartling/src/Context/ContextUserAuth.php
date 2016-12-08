<?php

namespace Drupal\tmgmt_smartling\Context;

use Drupal\tmgmt_smartling\Exceptions\WrongUsernameException;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\SessionManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use \Drupal\Core\Entity\EntityManagerInterface;
use Psr\Log\LoggerInterface;



class ContextUserAuth {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $account;

  /**
   * The user storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $userStorage;

  /**
   * The session manager service.
   *
   * @var \Drupal\Core\Session\SessionManagerInterface
   */
  protected $sessionManager;

  /**
   * The session.
   *
   * @var \Symfony\Component\HttpFoundation\Session\Session
   */
  protected $session;

  /**
   * @var LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new SwitchUserController object
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $account
   *   The current user.
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The user storage.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The user storage.
   * @param \Drupal\Core\Session\SessionManagerInterface $session_manager
   *   The session manager service.
   * @param \Symfony\Component\HttpFoundation\Session\Session $session
   *   The session.
   * @param \Psr\Log\LoggerInterface $logger
   */
  public function __construct(AccountProxyInterface $account, EntityManagerInterface $entity_manager, ModuleHandlerInterface $module_handler,
                              SessionManagerInterface $session_manager, Session $session, LoggerInterface $logger) {
    $this->account = $account;
    $this->userStorage = $entity_manager->getStorage('user');
    $this->moduleHandler = $module_handler;
    $this->sessionManager = $session_manager;
    $this->session = $session;
    $this->logger = $logger;
  }

  /**
   * Returns cookies of the needed user.
   *
   * @param string $name
   * @return string
   * @throws WrongUsernameException
   */
  public function getCookies($name) {
    if ($this->account->getAccountName() !== $name) {
      $this->switchUser($name);
    }

    return session_name() . "=" . session_id();
  }

  public function getCurrentAccount() {
    return $this->account;
  }

  /**
   * Switches to a different user.
   *
   * We don't call session_save_session() because we really want to change users.
   * Usually unsafe!
   *
   * @param string $name
   *   The username to switch to, or NULL to log out.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response object.
   *
   * @throws WrongUsernameException
   */
  public function switchUser($name = NULL) {
    $this->logger->info('We are about to switch user from "@user1" to "@user2"', ['@user1' => $this->account->getAccountName(), '@user2' => $name]);

    if (empty($name) || !($account = $this->userStorage->loadByProperties(['name' => $name]))) {
      throw new WrongUsernameException('User with username "@username" was not found', ['@username' => $name]);
    }
    $account = reset($account);

    // Call logout hooks when switching from original user.
    $this->moduleHandler->invokeAll('user_logout', [$this->account]);

    // Regenerate the session ID to prevent against session fixation attacks.
    $this->sessionManager->regenerate();

    // Based off masquarade module as:
    // https://www.drupal.org/node/218104 doesn't stick and instead only
    // keeps context until redirect.
    $this->account->setAccount($account);
    $this->session->set('uid', $account->id());

    // Call all login hooks when switching to masquerading user.
    $this->moduleHandler->invokeAll('user_login', [$account]);

    $this->logger->info('User was successfully switched to "@user" on IP = "@ip"', ['@user' => $name, '@ip' => $this->getIP()]);
  }

  protected function getIP() {
    return $_SERVER['REMOTE_ADDR'];
  }
}