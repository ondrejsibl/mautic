<?php

/*
 * @copyright   2019 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\AssetBundle\EventListener;

use Doctrine\ORM\NoResultException;
use Mautic\AssetBundle\AssetEvents;
use Mautic\AssetBundle\Model\AssetModel;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\TemplatingHelper;
use Mautic\CoreBundle\Helper\ThemeHelper;
use Mautic\CoreBundle\Templating\Helper\AnalyticsHelper;
use Mautic\CoreBundle\Templating\Helper\AssetsHelper;
use Mautic\FormBundle\Entity\Action;
use Mautic\FormBundle\Event\FormActionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Translation\TranslatorInterface;

class FormActionSubscriber implements EventSubscriberInterface
{
    /**
     * @var AssetModel
     */
    private $assetModel;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var AssetsHelper
     */
    private $templateAnalyticsHelper;

    /**
     * @var AnalyticsHelper
     */
    private $templateAssetsHelper;

    /**
     * @var ThemeHelper
     */
    private $themeHelper;

    /**
     * @var CoreParametersHelper
     */
    private $parametersHelper;

    /**
     * @var TemplatingHelper
     */
    private $templatingHelper;

    /**
     * @param AssetModel           $assetModel
     * @param TranslatorInterface  $translator
     * @param AnalyticsHelper      $templateAnalyticsHelper
     * @param AssetsHelper         $templateAssetsHelper
     * @param ThemeHelper          $themeHelper
     * @param CoreParametersHelper $parametersHelper
     * @param TemplatingHelper     $templatingHelper
     */
    public function __construct(
        AssetModel $assetModel,
        TranslatorInterface $translator,
        AnalyticsHelper $templateAnalyticsHelper,
        AssetsHelper $templateAssetsHelper,
        ThemeHelper $themeHelper,
        CoreParametersHelper $parametersHelper,
        TemplatingHelper $templatingHelper
    ) {
        $this->assetModel              = $assetModel;
        $this->translator              = $translator;
        $this->templateAnalyticsHelper = $templateAnalyticsHelper;
        $this->templateAssetsHelper    = $templateAssetsHelper;
        $this->themeHelper             = $themeHelper;
        $this->parametersHelper        = $parametersHelper;
        $this->templatingHelper        = $templatingHelper;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            AssetEvents::ON_FORM_ACTION_DOWNLOAD_START  => ['onFormActionSubmit', 0],
            AssetEvents::ON_FORM_ACTION_DOWNLOAD_FINISH => ['onFormActionDownloadFile', 0],
        ];
    }

    /**
     * @param FormActionEvent $event
     *
     * @return array
     */
    public function onFormActionSubmit(FormActionEvent $event)
    {
        $parameters = $event->getParameters();

        /** @var Action */
        $action     = $parameters['action'];
        $properties = $action->getProperties();
        $assetId    = $properties['asset'];
        $categoryId = isset($properties['category']) ? $properties['category'] : null;
        $model      = $this->assetModel;
        $asset      = null;

        if (null !== $assetId) {
            $asset = $model->getEntity($assetId);
        } elseif (null !== $categoryId) {
            try {
                $asset = $model->getRepository()->getLatestAssetForCategory($categoryId);
            } catch (NoResultException $e) {
                $asset = null;
            }
        }

        //make sure the asset still exists and is published
        if ($asset !== null && $asset->isPublished()) {
            //register a callback after the other actions have been fired
            return [
                'callback' => '\Mautic\AssetBundle\Helper\FormSubmitHelper::downloadFile',
                'form'     => $action->getForm(),
                'asset'    => $asset,
                'message'  => (isset($properties['message'])) ? $properties['message'] : '',
            ];
        }
    }

    /**
     * @param FormActionEvent $event
     *
     * @return array|Response
     */
    public function onFormActionDownloadFile(FormActionEvent $event)
    {
        $parameters    = $event->getParameters();
        $asset         = $parameters['asset'];
        $message       = $parameters['message'];
        $form          = $parameters['form'];
        $messengerMode = $parameters['messengerMode'];

        $model = $this->assetModel;
        $url   = $model->generateUrl($asset, true, ['form', $form->getId()]);

        if ($messengerMode) {
            return ['download' => $url];
        }

        $msg = $message.$this->translator->trans('mautic.asset.asset.submitaction.downloadfile.msg', [
                '%url%' => $url,
            ]);

        $analytics = $this->templateAnalyticsHelper->getCode();

        if (!empty($analytics)) {
            $this->templateAssetsHelper->addCustomDeclaration($analytics);
        }

        $logicalName = $this->themeHelper->checkForTwigTemplate(':'.$this->parametersHelper->getParameter('theme').':message.html.php');

        $content = $this->templatingHelper->getTemplating()->renderResponse($logicalName, [
            'message'  => $msg,
            'type'     => 'notice',
            'template' => $this->parametersHelper->getParameter('theme'),
        ])->getContent();

        return new Response($content);
    }
}
