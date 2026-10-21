<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Api;

use App\Entity\Timesheet;
use KimaiPlugin\AutomaticBreaksBundle\Notification\MessageFormatter;
use KimaiPlugin\AutomaticBreaksBundle\Notification\Receipt;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class NotificationController extends AbstractController
{
    #[Route('/api/automatic-breaks/notifications/{id}', name: 'automatic_breaks_notification', requirements: ['id' => '\d+'], defaults: ['_format' => 'json'], methods: ['GET'])]
    public function get(Timesheet $timesheet, Request $request, MessageFormatter $formatter): Response
    {
        $this->denyAccessUnlessGranted('view', $timesheet);
        $receipt = Receipt::fromTimesheet($timesheet);
        if ($receipt === null) {
            return new Response('', Response::HTTP_NO_CONTENT, ['Cache-Control' => 'private, no-store']);
        }
        $locale = $request->query->get('locale', $request->getLocale());
        if (!\is_string($locale) || !preg_match('/^[a-z]{2,3}(?:_[A-Za-z]{2,8}){0,2}$/D', $locale)) {
            throw $this->createNotFoundException('Invalid locale.');
        }

        return new JsonResponse($formatter->format($receipt, $locale), headers: ['Cache-Control' => 'private, no-store']);
    }
}
