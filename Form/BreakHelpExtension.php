<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Form;

use App\Entity\Timesheet;
use App\Form\API\TimesheetApiEditForm;
use App\Form\TimesheetAdminEditForm;
use App\Form\TimesheetEditForm;
use KimaiPlugin\AutomaticBreaksBundle\Calculation\BreakCalculator;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BreakHelpExtension extends AbstractTypeExtension
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public static function getExtendedTypes(): iterable
    {
        yield TimesheetEditForm::class;
        yield TimesheetAdminEditForm::class;
        yield TimesheetApiEditForm::class;
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $record = $form->getData();
        if (!$record instanceof Timesheet || !isset($view->children['break']) || $record->getMetaField(BreakCalculator::ORIGINAL) === null) {
            return;
        }
        $help = $this->translator->trans('automatic_breaks.editable');
        if ($record->getMetaField(BreakCalculator::STATUS)?->getValue() !== 'ok') {
            $help .= ' ' . $this->translator->trans('automatic_breaks.review');
        }
        $view->children['break']->vars['help'] = $help;
    }
}
