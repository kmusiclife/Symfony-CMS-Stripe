<?php

namespace SubscriptionBundle\EventListener;

use FOS\UserBundle\FOSUserEvents;
use FOS\UserBundle\Event\FormEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

use FOS\UserBundle\Event\FilterUserResponseEvent;
use FOS\UserBundle\Event\GetResponseUserEvent;

// source
use Symfony\Component\Form\FormError;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;

// Injection Classes
use FOS\UserBundle\Util\TokenGeneratorInterface;
use FOS\UserBundle\Mailer\MailerInterface;
use FOS\UserBundle\Model\UserManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class RegistrationListener implements EventSubscriberInterface
{

	protected $userManager;
	protected $serviceContainer;
	protected $entityManager;
	protected $router;
	
	protected $form;
	protected $user;

	public function __construct(
		MailerInterface $mailer,
		TokenGeneratorInterface $tokenGenerator, 
		ContainerInterface $serviceContainer, 
		UserManagerInterface $userManager, 
		EntityManagerInterface $entityManager, 
		UrlGeneratorInterface $router
	){
		$this->mailer = $mailer;
		$this->tokenGenerator = $tokenGenerator;
		$this->serviceContainer = $serviceContainer;
		$this->userManager = $userManager;
		$this->EntityManager = $entityManager;
		$this->router = $router;
		
		$this->form = null;
		$this->user = null;
	}

	public static function getSubscribedEvents()
	{
		return array(
			FOSUserEvents::REGISTRATION_INITIALIZE => 'onRegistrationInitialize',
			FOSUserEvents::REGISTRATION_SUCCESS => 'onRegistrationSuccess',
			FOSUserEvents::REGISTRATION_COMPLETED => 'onRegistrationComplete',
			FOSUserEvents::REGISTRATION_CONFIRM => 'onRegistrationConfirm',
			KernelEvents::EXCEPTION => 'onKernelException',
		);
	}
	public function onRegistrationConfirm(GetResponseUserEvent $event)
	{
		//$user = $event->getUser();
		//$request = $event->getRequest();
	}
	public function onRegistrationInitialize(GetResponseUserEvent $event)
	{
	}
	public function onRegistrationSuccess(FormEvent $event)
	{
		$this->form = $event->getForm();
		$this->user = $this->form->getData();
		
		try{
			
			$this->serviceContainer->get('subscription.stripe_helper')->setApiKey();
			
			$customer = \Stripe\Customer::create(array(
				"email" => $this->user->getEmail(),
				"description" => "",
				"source" => $this->user->getStripeTokenId(),
			));
			$this->user->setStripeCustomerId($customer->id);
			
			$subscription = \Stripe\Subscription::create(
				array(
					"customer" => $customer->id,
					"items" => array(
						array("plan" => $this->user->getStripePlanId()),
					),
					"application_fee_percent" => $this->serviceContainer->get('app.app_helper')->getParameter('stripe_application_fee'),
				),
				array(
					"stripe_account" => $this->serviceContainer->get('app.app_helper')->getSetting('access_token')
				)
			);
			$this->user->setStripeSubscriptionId($subscription->id);
			
			
		} catch (\Stripe\Error\RateLimit $e) {
			throw new Exception('クレジットカードの登録などの作業の間隔が速すぎのために処理できませんでした。時間を置いて登録してみてください。');
		} catch (\Stripe\Error\InvalidRequest $e) {
			throw new Exception('クレジットカード登録時にリクエストエラーが起こりました。何度も登録に失敗する場合は管理者にご連絡ください。');
		} catch (\Stripe\Error\Authentication $e) {
			throw new Exception('クレジットカード登録の接続に失敗しました。何度も登録に失敗する場合は管理者にご連絡ください。');
		} catch (\Stripe\Error\ApiConnection $e) {
			throw new Exception('クレジットカードAPIの接続に失敗しました。何度も登録に失敗する場合は管理者にご連絡ください。');
		} catch (\Stripe\Error\Base $e) {
			throw new Exception('クレジットカードの登録に失敗しました。有効期限などを確認してください。それでも解決しない場合はカード会社に確認してください。');
		} catch (Exception $e) {
			throw new Exception('クレジットカード登録時にシステムエラーが起こりました。何度も登録に失敗する場合は管理者にご連絡ください。');
		}
		
	}
	public function onRegistrationComplete(FilterUserResponseEvent $event)
	{
		/*
		$user = $event->getUser();
		$this->serviceContainer->get('subscription.stripe_helper')->setApiKey();
		$subscription = \Stripe\Subscription::retrieve( $user->getStripeSubscriptionId() );
		*/
	}
	public function onKernelException(GetResponseForExceptionEvent $event)
	{
		if( isset($this->form) ){
			
			$error = new FormError($event->getException()->getMessage());
			$this->form->get('stripe_token_id')->addError($error);
			
			$event->setResponse(
				new Response(
					$this->serviceContainer->get('templating')->render(
						'@FOSUser/Registration/register.html.twig', 
						array('form'=>$this->form->createView())
					)
				)
			);
			
		}
		return false;
		
	}

}
