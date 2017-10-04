<?php

namespace PH\PaymentHubBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Oro\Bundle\EmailBundle\Entity\EmailTemplate;
use Oro\Bundle\EmailBundle\Form\Model\Email;
use Oro\Bundle\EmailBundle\Mailer\Processor;
use Oro\Bundle\EmailBundle\Provider\EmailRenderer;
use PH\PaymentHubBundle\Entity\OrderItem;
use PH\PaymentHubBundle\Entity\Payment;
use PH\PaymentHubBundle\Entity\Subscription;
use PH\PaymentHubBundle\Entity\SubscriptionInterface;

/**
 * Class SubscriptionService.
 */
class SubscriptionService implements SubscriptionServiceInterface
{
    /**
     * @var Processor
     */
    protected $emailProcessor;

    /**
     * @var EmailRenderer
     */
    protected $emailRenderer;

    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @var string
     */
    protected $fromEmail;

    /**
     * SubscriptionService constructor.
     *
     * @param Processor              $emailProcessor
     * @param EmailRenderer          $emailRenderer
     * @param EntityManagerInterface $entityManager
     * @param                        $fromEmail
     */
    public function __construct(Processor $emailProcessor, EmailRenderer $emailRenderer, EntityManagerInterface $entityManager, $fromEmail)
    {
        $this->emailProcessor = $emailProcessor;
        $this->emailRenderer = $emailRenderer;
        $this->entityManager = $entityManager;
        $this->fromEmail = $fromEmail;
    }

    /**
     * {@inheritdoc}
     */
    public function processIncomingData(SubscriptionInterface $subscription, $data)
    {
        $subscription->setOrderState($data['state']);
        $subscription->setTotal($data['total'] / 100);
        $subscription->setOrderId($data['id']);
        $subscription->setCheckoutState($data['checkout_state']);
        $subscription->setPaymentState($data['payment_state']);
        $subscription->setCheckoutCompletedAt(new \DateTime($data['checkout_completed_at']));
        $subscription->setToken($data['token_value']);
        $subscription->setType($data['subscription']['type']);
        $subscription->setInterval($data['subscription']['interval']);
        $subscription->setStartDate(new \DateTime($data['subscription']['start_date']));

        $subscription->setItems($this->handleOrderItems($subscription, $data));
        $subscription->setPayments($this->handlePayments($subscription, $data));
    }

    /**
     * {@inheritdoc}
     */
    public function sendTransactionCompletedEmail(SubscriptionInterface $subscription)
    {
        $email = new Email();
        $emailTemplate = $this->entityManager
            ->getRepository(EmailTemplate::class)
            ->findByName('transaction_completed_customer');
        $templateData = $this->emailRenderer
            ->compileMessage($emailTemplate, ['entity' => $subscription]);
        list($subjectRendered, $templateRendered) = $templateData;

        $email->setSubject($subjectRendered);
        $email->setContexts([$subscription->getCustomer(), $subscription]);
        $email->setBody($templateRendered);
        $email->setTo([$subscription->getCustomer()->getEmail()]);
        $email->setFrom($this->fromEmail);

        $this->emailProcessor->process($email);

        return [
            'body' => $templateRendered,
            'subject' => $subjectRendered,
        ];
    }

    /**
     * @param SubscriptionInterface $subscription
     * @param array                 $data
     *
     * @return array
     */
    protected function handleOrderItems(SubscriptionInterface $subscription, $data)
    {
        $orderItems = [];
        $orderItemRepository = $this->entityManager->getRepository(OrderItem::class);
        foreach ($data['items'] as $key => $item) {
            $orderItem = $orderItemRepository->findOneBy(['orderItemId' => $item['id']]);
            if (null === $orderItem) {
                $orderItem = new OrderItem();
                $orderItem->setOrderItemId($item['id']);
                $orderItem->setCreatedAt(new \DateTime());
                $this->entityManager->persist($orderItem);
            } else {
                $orderItem->setUpdatedAt(new \DateTime());
            }

            $orderItem->setQuantity($item['quantity']);
            $orderItem->setUnitPrice($item['unit_price']);
            $orderItem->setTotal($item['total'] / 100);
            $orderItem->setName('change ME!!');
            $orderItem->setSubscription($subscription);
            $orderItems[] = $orderItem;
        }

        return $orderItems;
    }

    /**
     * @param SubscriptionInterface $subscription
     * @param array                 $data
     *
     * @return array
     */
    protected function handlePayments(SubscriptionInterface $subscription, $data)
    {
        $payments = [];
        $paymentRepository = $this->entityManager->getRepository(Payment::class);
        foreach ($data['payments'] as $singlePayment) {
            $payment = $paymentRepository->findOneBy(['paymentId' => $singlePayment['id']]);
            if (null === $payment) {
                $payment = new Payment();
                $payment->setPaymentId($singlePayment['id']);
                $payment->setCreatedAt(new \DateTime());
                $this->entityManager->persist($payment);
            } else {
                $payment->setUpdatedAt(new \DateTime());
            }

            $payment->setState($singlePayment['state']);
            $payment->setMethodCode($singlePayment['method']['code']);
            $subscription->setProviderType($singlePayment['method']['code']);
            $payment->setCurrencyCode($singlePayment['currency_code']);
            $payment->setAmount($singlePayment['amount'] / 100);
            $payment->setSubscription($subscription);
            $payments[] = $payment;
        }

        return $payments;
    }
}
