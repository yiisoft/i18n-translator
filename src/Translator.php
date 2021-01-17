<?php

declare(strict_types=1);

namespace Yiisoft\Translator;

use Psr\EventDispatcher\EventDispatcherInterface;
use Yiisoft\I18n\Locale;
use Yiisoft\Translator\Event\MissingTranslationCategoryEvent;
use Yiisoft\Translator\Event\MissingTranslationEvent;

/**
 * Translator translates a message into the specified language.
 */
class Translator implements TranslatorInterface
{
    private string $defaultCategory;
    private string $locale;
    private ?EventDispatcherInterface $eventDispatcher;
    private ?string $fallbackLocale;
    /**
     * @var Category[]
     */
    private array $categories = [];

    /**
     * @param Category $defaultCategory Default category to use if category is not specified explicitly.
     * @param string $locale Default locale to use if locale is not specified explicitly.
     * @param string|null $fallbackLocale Locale to use if message for the locale specified was not found. Null for none.
     * @param EventDispatcherInterface|null $eventDispatcher Event dispatcher for translation events. Null for none.
     */
    public function __construct(
        Category $defaultCategory,
        string $locale,
        ?string $fallbackLocale = null,
        ?EventDispatcherInterface $eventDispatcher = null
    ) {
        $this->defaultCategory = $defaultCategory->getName();
        $this->eventDispatcher = $eventDispatcher;
        $this->locale = $locale;
        $this->fallbackLocale = $fallbackLocale;

        $this->addCategorySource($defaultCategory);
    }

    public function addCategorySource(Category $category): void
    {
        if (isset($this->categories[$category->getName()])) {
            throw new \RuntimeException('Category "' . $category->getName() . '" already exists.');
        }
        $this->categories[$category->getName()] = $category;
    }

    /**
     * @param Category[] $categories
     */
    public function addCategorySources(array $categories): void
    {
        foreach ($categories as $category) {
            $this->addCategorySource($category);
        }
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function translate(
        string $id,
        array $parameters = [],
        string $category = null,
        string $locale = null
    ): string {
        $locale = $locale ?? $this->locale;

        $category = $category ?? $this->defaultCategory;

        if (empty($this->categories[$category])) {
            if ($this->eventDispatcher !== null) {
                $this->eventDispatcher->dispatch(new MissingTranslationCategoryEvent($category));
            }
            return $id;
        }

        $sourceCategory = $this->categories[$category];
        $message = $sourceCategory->getMessage($id, $locale, $parameters);

        if ($message === null) {
            if ($this->eventDispatcher !== null) {
                $this->eventDispatcher->dispatch(new MissingTranslationEvent($sourceCategory->getName(), $locale, $id));
            }

            $localeObject = new Locale($locale);
            $fallback = $localeObject->fallbackLocale();

            if ($fallback->asString() !== $localeObject->asString()) {
                return $this->translate($id, $parameters, $category, $fallback->asString());
            }

            if (!empty($this->fallbackLocale)) {
                $fallbackLocaleObject = (new Locale($this->fallbackLocale))->fallbackLocale();
                if ($fallbackLocaleObject->asString() !== $localeObject->asString()) {
                    return $this->translate($id, $parameters, $category, $fallbackLocaleObject->asString());
                }
            }

            $message = $id;
        }

        return $sourceCategory->format($message, $parameters, $locale);
    }

    /**
     * @psalm-immutable
     */
    public function withCategory(string $category): self
    {
        if (!isset($this->categories[$category])) {
            throw new \RuntimeException('Category with name "' . $category . '" does not exist.');
        }

        $new = clone $this;
        $new->defaultCategory = $category;
        return $new;
    }

    public function withLocale(string $locale): self
    {
        $new = clone $this;
        $new->setLocale($locale);
        return $new;
    }

    /**
     * @param mixed $translatableObject
     * @param string $messageClass
     * @param string $messageFunction
     * @param string $parametersFunction
     *
     * @return mixed
     */
    public function translateInstanceOf(
        $translatableObject,
        string $messageClass,
        string $messageFunction = 'getMessage',
        string $parametersFunction = 'getParameters'
    ) {
        if ($translatableObject instanceof $messageClass) {
            $parameters = $this->translateInstanceOf(
                $translatableObject->{$parametersFunction}(),
                $messageClass,
                $messageFunction,
                $parametersFunction
            );

            return $this->translate(
                $translatableObject->{$messageFunction}(),
                $parameters
            );
        }

        if (!is_iterable($translatableObject)) {
            return $translatableObject;
        }

        foreach ($translatableObject as &$value) {
            $value = $this->translateInstanceOf($value, $messageClass, $messageFunction, $parametersFunction);
        }

        return $translatableObject;
    }
}
