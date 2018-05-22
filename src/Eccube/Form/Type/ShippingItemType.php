<?php
/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) 2000-2015 LOCKON CO.,LTD. All Rights Reserved.
 *
 * http://www.lockon.co.jp/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

namespace Eccube\Form\Type;

use Eccube\Annotation\FormType;
use Eccube\Annotation\Inject;
use Eccube\Service\ShoppingService;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @FormType
 */
class ShippingItemType extends AbstractType
{
    /**
     * @Inject(ShoppingService::class)
     *
     * @var ShoppingService
     */
    protected $shoppingService;

    public $app;

    public function __construct(\Eccube\Application $app)
    {
        $this->app = $app;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $app = $this->app;

        $builder
            ->addEventListener(FormEvents::PRE_SET_DATA, function ($event) {
                /** @var \Eccube\Entity\Shipping $data */
                $data = $event->getData();
                /** @var \Symfony\Component\Form\Form $form */
                $form = $event->getForm();

                // お届け日を取得
                $deliveryDurations = $this->shoppingService->getFormDeliveryDurations($data->getOrder());

                // 配送業者
                // 販売種別に紐づく配送業者を取得
                $delives = $this->shoppingService->getDeliveriesOrder($data->getOrder());

                $deliveries = [];
                foreach ($delives as $Delivery) {
                    foreach ($data->getOrderItems() as $item) {
                        $saleType = $item->getProductClass()->getSaleType();
                        if ($Delivery->getSaleType()->getId() == $saleType->getId()) {
                            $deliveries[] = $Delivery;
                        }
                    }
                }

                $deliveryTimes = null;
                $delivery = $data->getDelivery();
                if ($delivery) {
                    $deliveryTimes = $delivery->getDeliveryTimes();
                }

                $form
                    ->add('delivery', EntityType::class, [
                        'class' => 'Eccube\Entity\Delivery',
                        'choice_label' => 'name',
                        'choices' => $deliveries,
                        'data' => $delivery,
                        'constraints' => [
                            new Assert\NotBlank(),
                        ],
                    ])
                    ->add('shippingDeliveryDuration', ChoiceType::class, [
                        'choices' => array_flip($deliveryDurations),
                        'required' => false,
                        'placeholder' => 'shippingitem.placeholder.not_selected',
                        'mapped' => false,
                    ])
                    ->add('deliveryTime', EntityType::class, [
                        'class' => 'Eccube\Entity\DeliveryTime',
                        'choice_label' => 'deliveryTime',
                        'choices' => $deliveryTimes,
                        'required' => false,
                        'placeholder' => 'shippingitem.placeholder.not_selected',
                    ]);
            })
            ->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) {
                /** @var \Eccube\Entity\Shipping $data */
                $data = $event->getData();
                /** @var \Symfony\Component\Form\Form $form */
                $form = $event->getForm();

                if (is_null($data)) {
                    return;
                }

                $shippingDeliveryDuration = $data->getShippingDeliveryDuration();
                if (!empty($shippingDeliveryDuration)) {
                    $form['shippingDeliveryDuration']->setData($shippingDeliveryDuration->format('Y/m/d'));
                }
            })
            ->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($app) {
                $data = $event->getData();
                $form = $event->getForm();
                if (!$data) {
                    return;
                }

                $value = $data['delivery'];
                if (empty($value)) {
                    $value = 0;
                }

                $deliveryTimes = null;
                $delivery = $app['eccube.repository.delivery']->find($value);
                if ($delivery) {
                    $deliveryTimes = $delivery->getDeliveryTimes();

                    if (isset($data['deliveryTime'])) {
                        $value = $data['deliveryTime'];
                        $filteredDeliveryTimes = $deliveryTimes->filter(function ($DeliveryTime) use ($value) {
                            return  $DeliveryTime->getId() == $value;
                        });
                        if (!$filteredDeliveryTimes->count()) {
                            $data['deliveryTime'] = null;
                            $event->setData($data);
                        }
                    }
                }

                // deliveryの値をもとにdeliveryTimeの選択肢を作り直す
                if ($form->has('deliveryTime')) {
                    $form->remove('deliveryTime');
                }
                $form->add('deliveryTime', 'entity', [
                    'class' => 'Eccube\Entity\DeliveryTime',
                    'property' => 'delivery_time',
                    'choices' => $deliveryTimes,
                    'required' => false,
                    'empty_value' => '指定なし',
                    'empty_data' => null,
                    'label' => 'お届け時間',
                ]);
            })
            ->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
                /** @var \Eccube\Entity\Shipping $data */
                $data = $event->getData();
                /** @var \Symfony\Component\Form\Form $form */
                $form = $event->getForm();
                $shippingDeliveryDuration = $form['shippingDeliveryDuration']->getData();
                if (!empty($shippingDeliveryDuration)) {
                    $data->setShippingDeliveryDuration(new \DateTime($form['shippingDeliveryDuration']->getData()));
                } else {
                    $data->setShippingDeliveryDuration(null);
                }
            });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => 'Eccube\Entity\Shipping',
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix()
    {
        return 'shipping_item';
    }
}
