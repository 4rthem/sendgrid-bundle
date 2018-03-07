<?php

namespace Arthem\Bundle\SendgridBundle\Transport;

use SendGrid;
use Swift_Events_EventListener;
use Swift_Mime_Attachment;
use Swift_Mime_Message;
use Swift_Transport;

class SendGridTransport implements Swift_Transport
{
    /**
     * @see https://sendgrid.com/docs/API_Reference/Web_API_v3/Mail/errors.html
     * 2xx responses indicate a successful request. The request that you made is valid and successful.
     */
    const STATUS_SUCCESSFUL_MAX_RANGE = 299;

    /**
     * @see https://sendgrid.com/docs/API_Reference/Web_API_v3/Mail/errors.html
     * ACCEPTED : Your message is both valid, and queued to be delivered.
     */
    const STATUS_ACCEPTED = 202;

    /**
     * @see https://sendgrid.com/docs/API_Reference/Web_API_v3/Mail/errors.html
     * OK : Your message is valid, but it is not queued to be delivered. Sandbox mode only.
     */
    const STATUS_OK_SUCCESSFUL_MIN_RANGE = 200;

    /**
     * @var SendGrid
     */
    private $sendGrid;

    /**
     * @var \Swift_Events_EventDispatcher
     */
    private $eventDispatcher;

    public function __construct(\Swift_Events_EventDispatcher $eventDispatcher, SendGrid $sendGrid)
    {
        $this->eventDispatcher = $eventDispatcher;
        $this->sendGrid = $sendGrid;
    }

    public function isStarted()
    {
        return true;
    }

    public function start()
    {
    }

    public function stop()
    {
    }

    /**
     * @param Swift_Mime_Message $message
     * @param array              $failedRecipients
     *
     * @return int
     */
    public function send(Swift_Mime_Message $message, &$failedRecipients = null)
    {
        $sent = 0;
        $failedRecipients = (array) $failedRecipients;

        if ($evt = $this->eventDispatcher->createSendEvent($this, $message)) {
            $this->eventDispatcher->dispatchEvent($evt, 'beforeSendPerformed');
            if ($evt->bubbleCancelled()) {
                return 0;
            }
        }

        $fromArray = $message->getFrom();
        $fromName = reset($fromArray);
        $fromEmail = key($fromArray);

        $toArray = $message->getTo();
        $toName = reset($toArray);
        $toEmail = key($toArray);
        ++$sent;
        array_shift($toArray);

        $content = new SendGrid\Content('text/html', $message->getBody());

        $mail = new SendGrid\Mail(
            new SendGrid\Email($fromName, $fromEmail),
            $message->getSubject(),
            new SendGrid\Email($toName, $toEmail),
            $content
        );

        /** @var \Swift_Mime_Header $category */
        foreach ($message->getHeaders()->getAll('X-SendGrid-Category') as $category) {
            $mail->addCategory($category->getFieldBody());
        }

        /** @var SendGrid\Personalization $personalization */
        $personalization = $mail->getPersonalizations()[0];

        // process TO
        foreach ($toArray as $email => $name) {
            $personalization->addTo(new SendGrid\Email($name, $email));
            ++$sent;
        }

        // process CC
        if ($ccArr = $message->getCc()) {
            foreach ($ccArr as $email => $name) {
                $personalization->addCc(new SendGrid\Email($name, $email));
                ++$sent;
            }
        }

        // process BCC
        if ($bccArr = $message->getBcc()) {
            foreach ($bccArr as $email => $name) {
                $personalization->addBcc(new SendGrid\Email($name, $email));
                ++$sent;
            }
        }

        // process attachment (not inline)
        if ($attachments = $message->getChildren()) {
            foreach ($attachments as $attachment) {
                if ($attachment instanceof Swift_Mime_Attachment) {
                    $sAttachment = new SendGrid\Attachment();
                    $sAttachment->setContent(base64_encode($attachment->getBody()));
                    $sAttachment->setType($attachment->getContentType());
                    $sAttachment->setFilename($attachment->getFilename());
                    $sAttachment->setDisposition($attachment->getDisposition());
                    $sAttachment->setContentId($attachment->getId());
                    $mail->addAttachment($sAttachment);
                }
            }
        }

        /** @var SendGrid\Response $response */
        $response = $this->sendGrid->client->mail()->send()->post($mail);
        $resultStatus = \Swift_Events_SendEvent::RESULT_SUCCESS;

        if (
            $response->statusCode() < self::STATUS_OK_SUCCESSFUL_MIN_RANGE
            || self::STATUS_SUCCESSFUL_MAX_RANGE < $response->statusCode()
        ) {
            $failedRecipients = $message->getTo();
            $sent = 0;
            $resultStatus = \Swift_Events_SendEvent::RESULT_FAILED;
        }

        if ($evt) {
            $evt->setResult($resultStatus);
            $evt->setFailedRecipients($failedRecipients);
            $this->eventDispatcher->dispatchEvent($evt, 'sendPerformed');
        }

        return $sent;
    }

    public function registerPlugin(Swift_Events_EventListener $plugin)
    {
        $this->eventDispatcher->bindEventListener($plugin);
    }
}
