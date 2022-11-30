<?php

declare(strict_types=1);

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\UX\LiveComponent;

use Symfony\Component\Form\ClearableErrorsInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * @author Ryan Weaver <ryan@symfonycasts.com>
 *
 * @experimental
 */
trait ComponentWithFormTrait
{
    #[ExposeInTemplate(name: 'form', getter: 'getForm')]
    private ?FormView $formView = null;
    private ?FormInterface $formInstance = null;

    /**
     * Holds the name prefix the form uses.
     */
    #[LiveProp]
    public ?string $formName = null;

    /**
     * Holds the raw form values.
     */
    #[LiveProp(writable: true, fieldName: 'getFormName()')]
    public ?array $formValues = null;

    /**
     * Indicates if the form was submitted through a live component action instead of a regular rerender.
     */
    #[LiveProp(writable: true)]
    public bool $wasSubmitted = false;

    #[LiveProp(writable: true)]
    public string $validationMode = 'late'; // Can be 'early|late'

    /**
     * Return the full, top-level, Form object that this component uses.
     */
    abstract protected function instantiateForm(): FormInterface;

    abstract protected function configureModelBehavior(ModelBehavior $models): void;

    /**
     * @internal
     */
    #[PostMount]
    public function initializeForm(array $data): array
    {
        // allow the FormView object to be passed into the component() as "form"
        if (\array_key_exists('form', $data)) {
            $this->formView = $this->configureFormView($data['form']);
            unset($data['form']);
        }

        // set the formValues from the initial form view's data
        $this->formValues = $this->extractFormValues($this->getForm());

        return $data;
    }

    /**
     * Make sure the form has been submitted.
     *
     * This primarily applies to a re-render where $actionName is null.
     * But, in the event that there is an action and the form was
     * not submitted manually, it will be submitted here.
     *
     * @internal
     */
    #[PreReRender]
    public function hydrateFormOnRender(): void
    {
        $this->hydrateForm();
    }

    /**
     * Returns the FormView object: useful for rendering your form/fields!
     */
    public function getForm(): FormView
    {
        if (null === $this->formView) {
            $this->formView = $this->configureFormView(
                $this->getFormInstance()->createView()
            );
        }

        return $this->formView;
    }

    public function getFormName(): string
    {
        if (!$this->formName) {
            $this->formName = $this->getForm()->vars['name'];
        }

        return $this->formName;
    }

    private function hydrateForm(): FormInterface
    {
        $form = $this->getFormInstance();
        if ($form->isSubmitted()) {
            return $form;
        }

        $form->submit($this->formValues);

        $isValid = $form->isValid();
        $unprocessable = false;
        if ('early' === $this->validationMode && !$isValid) {
            $unprocessable = true;
        }

        if ('late' === $this->validationMode && !$isValid) {
            if ($this->wasSubmitted) {
                $unprocessable = true;
            } else {
                $this->clearErrorsForNonValidatedFields($form);
            }
        }

        // re-extract the "view" values in case the submitted data
        // changed the underlying data or structure of the form
        $this->formValues = $this->extractFormValues($this->getForm());

        if ($unprocessable) {
            throw new UnprocessableEntityHttpException('Form validation failed in component');
        }

        return $form;
    }

    private function submitForm(): void
    {
        $this->wasSubmitted = true;
        $form = $this->hydrateForm();

        if (!$form->isValid()) {
            throw new UnprocessableEntityHttpException('Form validation failed in component');
        }
    }

    private function getFormInstance(): FormInterface
    {
        if (null === $this->formInstance) {
            $this->formInstance = $this->instantiateForm();
        }

        return $this->formInstance;
    }

    private function configureFormView(FormView $form): FormView
    {
        $behavior = new ModelBehavior();
        $this->configureModelBehavior($behavior);
        $this->applyDataModelsToForm($form, $behavior);

        return $form;
    }

    private function applyDataModelsToForm(FormView $form, ModelBehavior $behavior): void
    {
        foreach ($form->children as $child) {
            $attr = $child->vars['attr'] ?? [];
            $fullName = $child->vars['full_name'] ?? '';

            $attr['data-model'] = $behavior->getModelForField($fullName);
            $attr['data-successful'] = json_encode($this->wasSubmitted && !count($child->vars['errors'] ?? []));
            $child->vars['attr'] = $attr;

            $this->applyDataModelsToForm($child, $behavior);
        }
    }

    /**
     * Returns a hierarchical array of the entire form's values.
     *
     * This is used to pass the initial values into the live component's
     * frontend, and it's meant to equal the raw POST data that would
     * be sent if the form were submitted without modification.
     */
    private function extractFormValues(FormView $formView): array
    {
        $values = [];

        foreach ($formView->children as $child) {
            $name = $child->vars['name'];

            // if there are children, expand their values recursively
            // UNLESS the field is "expanded": in that case the value
            // is already correct. For example, an expanded ChoiceType with
            // options "text" and "phone" would already have a value in the format
            // ["text"] (assuming "text" is checked and "phone" is not).
            if (!($child->vars['expanded'] ?? false) && \count($child->children) > 0) {
                $values[$name] = $this->extractFormValues($child);

                continue;
            }

            if (\array_key_exists('checked', $child->vars)) {
                // special handling for check boxes
                $values[$name] = $child->vars['checked'] ? $child->vars['value'] : null;
            } else {
                $values[$name] = $child->vars['value'];
            }
        }

        return $values;
    }

    private function clearErrorsForNonValidatedFields(FormInterface $form): void
    {
        if ($form instanceof ClearableErrorsInterface) {
            $form->clearErrors(true);
        }
    }
}
