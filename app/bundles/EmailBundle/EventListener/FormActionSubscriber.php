<?php

/*
 * @copyright   2019 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\EmailBundle\EventListener;

use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\FormBundle\Entity\Action;
use Mautic\FormBundle\Event\FormActionEvent;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class FormActionSubscriber implements EventSubscriberInterface
{
    /**
     * @var LeadModel
     */
    protected $leadModel;

    /**
     * @var EmailModel
     */
    protected $emailModel;

    /**
     * @param LeadModel  $leadModel
     * @param EmailModel $emailModel
     */
    public function __construct(
        LeadModel $leadModel,
        EmailModel $emailModel
    ) {
        $this->leadModel  = $leadModel;
        $this->emailModel = $emailModel;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            EmailEvents::ON_FORM_ACTION_SEND => ['onFormActionSend', 0],
        ];
    }

    /**
     * @param FormActionEvent $event
     *
     * @throws \Doctrine\ORM\ORMException
     */
    public function onFormActionSend(FormActionEvent $event)
    {
        $parameters = $event->getParameters();

        /** @var Action */
        $action     = $parameters['action'];
        $properties = $action->getProperties();
        $form       = $action->getForm();
        $feedback   = $parameters['feedback'];
        $emailId    = (isset($properties['useremail'])) ? (int) $properties['useremail']['email'] : (int) $properties['email'];
        $tokens     = $parameters['tokens'];

        $emailModel = $this->emailModel;
        $email      = $emailModel->getEntity($emailId);

        $leadModel = $this->leadModel;

        //make sure the email still exists and is published
        if ($email != null && $email->isPublished()) {
            // Deal with Lead email
            if (!empty($feedback['lead.create']['lead'])) {
                //the lead was just created via the lead.create action
                $currentLead = $feedback['lead.create']['lead'];
            } else {
                $currentLead = $leadModel->getCurrentLead();
            }

            if ($currentLead instanceof Lead) {
                //flatten the lead
                $lead        = $currentLead;
                $currentLead = [
                    'id' => $lead->getId(),
                ];
                $leadFields = $leadModel->flattenFields($lead->getFields());

                $currentLead = array_merge($currentLead, $leadFields);
            }

            if (isset($properties['user_id']) && $properties['user_id']) {
                // User email
                $emailModel->sendEmailToUser($email, $properties['user_id'], $currentLead, $tokens);
            } elseif (isset($currentLead)) {
                if (isset($leadFields['email'])) {
                    $options = [
                        'source'    => ['form', $form->getId()],
                        'tokens'    => $tokens,
                        'ignoreDNC' => true,
                    ];
                    $emailModel->sendEmail($email, $currentLead, $options);
                }
            }
        }
    }
}
