<?php

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
use Symfony\UX\LiveComponent\Util\LiveFormUtility;
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
     * Indicates if the form was submitted through a live component action instead of a regular rerender
     */
    #[LiveProp(writable: true)]
    public bool $wasSubmitted = false;


    #[LiveProp(writable: true)]
    public string $validationMode = 'late'; // Can be 'early|late'

    /**
     * Return the full, top-level, Form object that this component uses.
     */
    abstract protected function instantiateForm(): FormInterface;

    /**
     * @internal
     */
    #[PostMount]
    public function initializeForm(array $data): array
    {
        // allow the FormView object to be passed into the component() as "form"
        if (\array_key_exists('form', $data)) {
            $this->formView = $data['form'];
            $this->useNameAttributesAsModelName();

            unset($data['form']);

            // if a FormView is passed in and it contains any errors, then
            // we mark that this entire component has been validated so that
            // all validation errors continue showing on re-render
            // TODO : changed code relies on submitted state (which is basically what sets the errors)
            // TODO : Is that sufficient?
            /*if ($this->formView && LiveFormUtility::doesFormContainAnyErrors($this->formView)) {
                $this->isValidated = true;
                $this->validatedFields = [];
            }*/
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
            $this->formView = $this->getFormInstance()->createView();
            $this->useNameAttributesAsModelName();
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
        /*if (null !== $this->formView) {
            return $this->formView;
        }*/

        $form = $this->getFormInstance();
        if ($form->isSubmitted()) {
            return $form;
        }

        $form->submit($this->formValues);

        $isValid = $form->isValid();
        $unprocessable = false;
        if ($this->validationMode === 'early' && !$isValid) {
            $unprocessable = true;
        }

        if ($this->validationMode === 'late' && !$isValid) {
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

    /**
     * Automatically adds data-model="*" to the form element.
     *
     * This makes it so that all fields will automatically become
     * "models", using their "name" attribute.
     *
     * This is for convenience: it prevents you from needing to
     * manually add data-model="" to every field. Effectively,
     * having name="foo" becomes the equivalent to data-model="foo".
     *
     * To disable or change this behavior, override the
     * the getDataModelValue() method.
     */
    private function useNameAttributesAsModelName(): void
    {
        $modelValue = $this->getDataModelValue();
        $attributes = $this->getForm()->vars['attr'] ?: [];
        if (null === $modelValue) {
            unset($attributes['data-model']);
        } else {
            $attributes['data-model'] = $modelValue;
        }

        $this->getForm()->vars['attr'] = $attributes;
    }

    /**
     * Controls the data-model="" value that will be rendered on the <form> tag.
     *
     * This default value will cause the component to re-render each time
     * a field "changes". Override this in your controller to change the behavior.
     */
    private function getDataModelValue(): ?string
    {
        return 'on(change)|*';
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

    /**
     * TODO : Bring back logic in here.
     */
    private function clearErrorsForNonValidatedFields(FormInterface $form, string $currentPath = ''): void
    {
        if ($form instanceof ClearableErrorsInterface && (!$currentPath || !\in_array($currentPath, [], true))) {
            $form->clearErrors(true);
        }

        foreach ($form as $name => $child) {
            $this->clearErrorsForNonValidatedFields($child, sprintf('%s.%s', $currentPath, $name));
        }
    }
}
