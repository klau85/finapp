<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\BrokerAccount;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BrokerAccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('brokerType', ChoiceType::class, [
                'choices' => [
                    'Custom' => 'custom',
                    'XTB' => 'xtb',
                    'Revolut' => 'revolut',
                    'IBKR' => 'ibkr',
                ],
            ])
            ->add('displayName', TextType::class, [
                'attr' => ['placeholder' => 'XTB Long-Term Account'],
            ])
            ->add('accountIdentifier', TextType::class, [
                'required' => false,
                'attr' => ['placeholder' => 'Optional account number or nickname'],
            ])
            ->add('currency', ChoiceType::class, [
                'choices' => [
                    'USD' => 'USD',
                    'EUR' => 'EUR',
                    'RON' => 'RON',
                    'GBP' => 'GBP',
                    'PLN' => 'PLN',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BrokerAccount::class,
        ]);
    }
}
