<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\JournalEntry;
use App\Entity\Stock;
use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\StockRepository;
use App\Repository\TransactionRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class JournalEntryType extends AbstractType
{
    public function __construct(
        private readonly StockRepository $stockRepository,
        private readonly TransactionRepository $transactionRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User $user */
        $user = $options['user'];
        $lockedTarget = $options['locked_target'];

        $builder
            ->add('targetType', ChoiceType::class, [
                'label' => 'Target type',
                'choices' => [
                    'Portfolio' => JournalEntry::TARGET_PORTFOLIO,
                    'Stock' => JournalEntry::TARGET_STOCK,
                    'Transaction' => JournalEntry::TARGET_TRANSACTION,
                ],
                'disabled' => $lockedTarget,
                'attr' => ['data-journal-target' => ''],
            ])
            ->add('stock', EntityType::class, [
                'class' => Stock::class,
                'choices' => $this->stockRepository->findForUser($user),
                'choice_label' => static fn (Stock $stock): string => $stock->getCompanyName() !== null
                    ? sprintf('%s - %s', $stock->getSymbol(), $stock->getCompanyName())
                    : $stock->getSymbol(),
                'placeholder' => 'Select stock',
                'required' => false,
                'disabled' => $lockedTarget,
            ])
            ->add('transaction', EntityType::class, [
                'class' => Transaction::class,
                'choices' => $this->transactionRepository->findForJournalChoices($user),
                'choice_label' => static function (Transaction $transaction): string {
                    $stock = $transaction->getStock();
                    $account = $transaction->getBrokerAccount();

                    return sprintf(
                        '%s %s %s @ %s %s on %s - %s',
                        $transaction->getType(),
                        $transaction->getQuantity(),
                        $stock?->getSymbol() ?? '',
                        $transaction->getPrice(),
                        $transaction->getCurrency(),
                        $transaction->getTransactionDate()->format('Y-m-d'),
                        $account?->getDisplayName() ?? '',
                    );
                },
                'placeholder' => 'Select transaction',
                'required' => false,
                'disabled' => $lockedTarget,
            ])
            ->add('entryType', ChoiceType::class, [
                'label' => 'Entry type',
                'choices' => array_combine(
                    array_map(static fn (string $type): string => ucwords(strtolower(str_replace('_', ' ', $type))), JournalEntry::ENTRY_TYPES),
                    JournalEntry::ENTRY_TYPES,
                ),
            ])
            ->add('title', TextType::class, [
                'required' => false,
                'attr' => ['maxlength' => 180, 'placeholder' => 'Optional title'],
            ])
            ->add('content', TextareaType::class, [
                'attr' => ['rows' => 10, 'placeholder' => 'Record what you believe, why you made the decision, and what could change your view.'],
            ])
            ->add('entryDate', DateType::class, [
                'label' => 'Entry date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => JournalEntry::class,
            'locked_target' => false,
        ]);
        $resolver->setRequired('user');
        $resolver->setAllowedTypes('user', User::class);
        $resolver->setAllowedTypes('locked_target', 'bool');
    }
}
