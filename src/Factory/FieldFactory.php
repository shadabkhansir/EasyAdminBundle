<?php

namespace EasyCorp\Bundle\EasyAdminBundle\Factory;

use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TimeField;
use EasyCorp\Bundle\EasyAdminBundle\Provider\AdminContextProvider;
use EasyCorp\Bundle\EasyAdminBundle\Security\Permission;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * @author Javier Eguiluz <javier.eguiluz@gmail.com>
 */
final class FieldFactory
{
    // TODO: update this map when ArrayField is implemented
    private static $doctrineTypeToFieldFqcn = [
        //Type::TARRAY => 'array',
        Types::BIGINT => TextField::class,
        Types::BINARY => TextareaField::class,
        Types::BLOB => TextareaField::class,
        Types::BOOLEAN => BooleanField::class,
        Types::DATE_MUTABLE => DateField::class,
        Types::DATE_IMMUTABLE => DateField::class,
        Types::DATEINTERVAL => TextField::class,
        Types::DATETIME_MUTABLE => DateTimeField::class,
        Types::DATETIME_IMMUTABLE => DateTimeField::class,
        Types::DATETIMETZ_MUTABLE => DateTimeField::class,
        Types::DATETIMETZ_IMMUTABLE => DateTimeField::class,
        Types::DECIMAL => NumberField::class,
        Types::FLOAT => NumberField::class,
        Types::GUID => TextField::class,
        Types::INTEGER => IntegerField::class,
        Types::JSON => TextField::class,
        Types::OBJECT => TextField::class,
        //Types::SIMPLE_ARRAY => 'array',
        Types::SMALLINT => IntegerField::class,
        Types::STRING => TextField::class,
        Types::TEXT => TextareaField::class,
        Types::TIME_MUTABLE => TimeField::class,
        Types::TIME_IMMUTABLE => TimeField::class,
    ];

    private $adminContextProvider;
    private $authorizationChecker;
    private $fieldConfigurators;

    public function __construct(AdminContextProvider $adminContextProvider, AuthorizationCheckerInterface $authorizationChecker, iterable $fieldConfigurators)
    {
        $this->adminContextProvider = $adminContextProvider;
        $this->authorizationChecker = $authorizationChecker;
        $this->fieldConfigurators = $fieldConfigurators;
    }

    public function processFields(EntityDto $entityDto, FieldCollection $fields): void
    {
        $this->preProcessFields($fields, $entityDto);

        foreach ($fields as $fieldName => $fieldDto) {
            if (false === $this->authorizationChecker->isGranted(Permission::EA_VIEW_FIELD, $fieldDto)) {
                $fields->unset($fieldDto);

                continue;
            }

            $context = $this->adminContextProvider->getContext();
            foreach ($this->fieldConfigurators as $configurator) {
                if (!$configurator->supports($fieldDto, $entityDto)) {
                    continue;
                }

                $configurator->configure($fieldDto, $entityDto, $context);
            }

            $fields->set($fieldDto);
        }

        $entityDto->setFields($fields);
    }

    private function preProcessFields(FieldCollection $fields, EntityDto $entityDto): void
    {
        foreach ($fields as $fieldName => $fieldDto) {
            if (Field::class !== $fieldDto->getFieldFqcn()) {
                continue;
            }

            // this is a virtual field, so we can't autoconfigure it
            if (!$entityDto->hasProperty($fieldDto->getProperty())) {
                continue;
            }

            if ($fieldName === $entityDto->getPrimaryKeyName()) {
                $guessedFieldFqcn = IdField::class;
            } else {
                $doctrinePropertyType = $entityDto->getPropertyMetadata($fieldName)->get('type');
                $guessedFieldFqcn = self::$doctrineTypeToFieldFqcn[$doctrinePropertyType] ?? null;

                if (null === $guessedFieldFqcn) {
                    throw new \RuntimeException(sprintf('The Doctrine type of the "%s" field is "%s", which is not supported by EasyAdmin yet.', $fieldName, $doctrinePropertyType));
                }
            }

            $fields->set($this->transformField($fieldDto, $guessedFieldFqcn));
        }
    }

    private function transformField(FieldDto $fieldDto, string $newFieldFqcn): FieldDto
    {
        /** @var FieldDto $newField */
        $newField = $newFieldFqcn::new($fieldDto->getProperty())->getAsDto();

        $newField->setFieldFqcn($newFieldFqcn);
        $newField->setValue($fieldDto->getValue());
        $newField->setFormattedValue($fieldDto->getFormattedValue());
        $newField->setCssClass($fieldDto->getCssClass());
        $newField->setTranslationParameters($fieldDto->getTranslationParameters());
        $newField->setAssets($newField->getAssets()->mergeWith($fieldDto->getAssets()));

        $customFormTypeOptions = $fieldDto->getFormTypeOptions();
        $defaultFormTypeOptions = $newField->getFormTypeOptions();
        $newField->setFormTypeOptions(array_merge($defaultFormTypeOptions, $customFormTypeOptions));

        $customFieldOptions = $fieldDto->getCustomOptions()->all();
        $defaultFieldOptions = $newField->getCustomOptions()->all();
        $mergedFieldOptions = array_merge($defaultFieldOptions, $customFieldOptions);
        $newField->setCustomOptions($mergedFieldOptions);

        if (null !== $fieldDto->getLabel()) {
            $newField->setLabel($fieldDto->getLabel());
        }

        if (null !== $fieldDto->isVirtual()) {
            $newField->setVirtual($fieldDto->isVirtual());
        }

        if (null !== $fieldDto->getTextAlign()) {
            $newField->setTextAlign($fieldDto->getTextAlign());
        }

        if (null !== $fieldDto->isSortable()) {
            $newField->setSortable($fieldDto->isSortable());
        }

        if (null !== $fieldDto->getPermission()) {
            $newField->setPermission($fieldDto->getPermission());
        }

        if (null !== $fieldDto->getHelp()) {
            $newField->setHelp($fieldDto->getHelp());
        }

        if (null !== $fieldDto->getFormType()) {
            $newField->setFormType($fieldDto->getFormType());
        }

        if (null !== $fieldDto->getTemplateName()) {
            $newField->setTemplateName($fieldDto->getTemplateName());
        }

        if (null !== $fieldDto->getTemplatePath()) {
            $newField->setTemplatePath($fieldDto->getTemplatePath());
        }

        return $newField;
    }
}
