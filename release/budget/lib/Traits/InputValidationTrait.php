<?php

declare(strict_types=1);

namespace OCA\Budget\Traits;

use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;

/**
 * Trait for input validation in controllers.
 *
 * Provides helper methods to validate and sanitize user input
 * with consistent error handling.
 */
trait InputValidationTrait {
    protected ?ValidationService $inputValidator = null;

    /**
     * Set the validation service instance.
     */
    protected function setInputValidator(ValidationService $validator): void {
        $this->inputValidator = $validator;
    }

    /**
     * Get the validation service, creating a default if not set.
     */
    protected function getInputValidator(): ValidationService {
        if ($this->inputValidator === null) {
            $this->inputValidator = new ValidationService();
        }
        return $this->inputValidator;
    }

    /**
     * Validate multiple fields at once.
     *
     * @param array $validations Array of ['field' => 'value', 'type' => 'name|description|etc', 'required' => bool]
     * @return array ['valid' => bool, 'errors' => array, 'sanitized' => array]
     */
    protected function validateInputs(array $validations): array {
        $errors = [];
        $sanitized = [];
        $validator = $this->getInputValidator();

        foreach ($validations as $fieldName => $config) {
            $value = $config['value'] ?? null;
            $type = $config['type'] ?? 'string';
            $required = $config['required'] ?? false;
            $maxLength = $config['maxLength'] ?? null;

            $result = match ($type) {
                'name' => $validator->validateName($value, $required),
                'description' => $validator->validateDescription($value, $required),
                'notes' => $validator->validateNotes($value),
                'vendor' => $validator->validateVendor($value),
                'reference' => $validator->validateReference($value),
                'pattern' => $validator->validatePattern($value, $required),
                'icon' => $validator->validateIcon($value),
                'color' => $validator->validateColor($value),
                'date' => $validator->validateDate($value, $fieldName, $required),
                'amount' => $validator->validateAmount($value, $fieldName, $required),
                'currency' => $validator->validateCurrency($value ?? ''),
                'accountType' => $validator->validateAccountType($value ?? ''),
                default => $maxLength !== null
                    ? $validator->validateStringLength($value, $fieldName, $maxLength, 0, $required)
                    : ['valid' => true, 'sanitized' => $value, 'error' => null]
            };

            if (!$result['valid']) {
                $errors[$fieldName] = $result['error'];
            } else {
                // Get the sanitized/formatted value
                $sanitized[$fieldName] = $result['sanitized'] ?? $result['formatted'] ?? $result['value'] ?? $value;
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'sanitized' => $sanitized
        ];
    }

    /**
     * Create a validation error response.
     *
     * @param array $errors Validation errors by field
     * @return DataResponse
     */
    protected function validationErrorResponse(array $errors): DataResponse {
        $message = count($errors) === 1
            ? array_values($errors)[0]
            : 'Validation failed';

        return new DataResponse([
            'error' => $message,
            'validation_errors' => $errors
        ], Http::STATUS_BAD_REQUEST);
    }

    /**
     * Quick validation for a single name field.
     *
     * @param string|null $name
     * @param bool $required
     * @return DataResponse|null Returns error response if invalid, null if valid
     */
    protected function validateNameInput(?string $name, bool $required = true): ?DataResponse {
        $result = $this->getInputValidator()->validateName($name, $required);
        if (!$result['valid']) {
            return new DataResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
        }
        return null;
    }

    /**
     * Quick validation for a description field.
     *
     * @param string|null $description
     * @return DataResponse|null Returns error response if invalid, null if valid
     */
    protected function validateDescriptionInput(?string $description): ?DataResponse {
        $result = $this->getInputValidator()->validateDescription($description);
        if (!$result['valid']) {
            return new DataResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
        }
        return null;
    }

    /**
     * Quick validation for an amount field.
     *
     * @param mixed $amount
     * @param string $fieldName
     * @param bool $required
     * @return DataResponse|null Returns error response if invalid, null if valid
     */
    protected function validateAmountInput(mixed $amount, string $fieldName = 'Amount', bool $required = true): ?DataResponse {
        $result = $this->getInputValidator()->validateAmount($amount, $fieldName, $required);
        if (!$result['valid']) {
            return new DataResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
        }
        return null;
    }
}
