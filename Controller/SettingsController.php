<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Controller;

use App\Configuration\SystemConfiguration;
use App\Controller\AbstractController;
use App\Utils\PageSetup;
use KimaiPlugin\AutomaticBreaksBundle\Calculation\Policy;
use KimaiPlugin\AutomaticBreaksBundle\Configuration\Settings;
use KimaiPlugin\AutomaticBreaksBundle\Form\PolicyDataMapper;
use KimaiPlugin\AutomaticBreaksBundle\Form\SettingsType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: '/admin/automatic-breaks')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[IsGranted('system_configuration')]
final class SettingsController extends AbstractController
{
    #[Route(path: '', name: 'automatic_breaks_settings', methods: ['GET', 'POST'])]
    public function index(Request $request, Settings $settings, PolicyDataMapper $mapper, TranslatorInterface $translator, SystemConfiguration $configuration): Response
    {
        $policy = $settings->getPolicy();
        $form = $this->createForm(SettingsType::class, $mapper->toForm($policy));
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $values = $form->getData();
            if (!\is_array($values)) {
                throw new \LogicException('Unexpected settings form data.');
            }
            try {
                $updated = $mapper->fromForm($values);
            } catch (\InvalidArgumentException) {
                $form->addError(new FormError($translator->trans('automatic_breaks.invalid')));
                $updated = null;
            }
            if ($updated !== null) {
                $settings->save($updated);
                $this->flashSuccess('action.update.success');

                return $this->redirectToRoute('automatic_breaks_settings');
            }
        }

        return $this->render('@AutomaticBreaks/settings.html.twig', [
            'page_setup' => new PageSetup('automatic_breaks.title'),
            'form' => $form->createView(),
            'max_rules' => Policy::MAX_RULES,
            'breaks_enabled' => $configuration->isBreakTimeEnabled(),
        ]);
    }
}
