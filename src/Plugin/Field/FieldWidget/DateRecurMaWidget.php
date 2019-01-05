<?php

namespace Drupal\date_recur_ma_widget\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Datetime\DateHelper;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\date_recur\DateRecurHelper;
use Drupal\date_recur\DateRecurRruleMap;
use Drupal\date_recur\Plugin\Field\FieldWidget\DateRecurBasicWidget;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use RRule\RRule;

/**
 * Widget for recurring dates field built using Drupal AJAX.
 *
 * @FieldWidget(
 *   id = "date_recur_ma_widget",
 *   label = @Translation("Recurring dates widget (MA)"),
 *   field_types = {
 *     "date_recur"
 *   }
 * )
 */
class DateRecurMaWidget extends DateRecurBasicWidget {

  public function defaultValues() {
    return [
      'repeat' => '',
      'repeat_settings' => [
        'interval' => 1,
        'until_op' => 'never',
        'until' => [
          'date' => [
            'until_date' => NULL,
          ],
          'count' => [
            'until_count' => NULL,
          ],
        ],
        'day_of_week' => [],
        'week_of_month' => [],
        'month_of_year' => [],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      // @todo needs config schema.
      'allowed_repeat_types' => DateRecurRruleMap::FREQUENCIES,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);
    $elements['allowed_repeat_types'] = [
      '#title' => 'Repeat',
      '#type' => 'checkboxes',
      '#options' => DateRecurRruleMap::frequencyLabels(),
      '#default_value' => $this->getSetting('allowed_repeat_types'),
    ];
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $parents = $element['#field_parents'];
    $widget_state = static::getWidgetState($parents, $field_name, $form_state);

    $selector_parents = array_merge($element['#field_parents'], [
      $this->fieldDefinition->getName(),
      $delta,
    ]);
    $repeat_parents = $selector_parents;
    $repeat_parents[] = 'repeat';
    $repeat_selector_parts = $repeat_parents;
    $repeat_selector = array_shift($repeat_selector_parts) . '[' . implode('][', $repeat_selector_parts) . ']';

    $repeat_configure_parents = $selector_parents;
    $repeat_configure_parents[] = 'repeat_configure';
    $repeat_configure_selector = array_shift($repeat_configure_parents) . '[' . implode('][', $repeat_configure_parents) . ']';


    $rrule = isset($items[$delta]->rrule) ? $items[$delta]->rrule : NULL;
    $values = NestedArray::getValue($form_state->getValues(), $selector_parents);
    if ($rrule) {
      $rrule_values = self::getValueFromRRule($rrule);
      $values = NestedArray::mergeDeep($rrule_values, (array) $values);
      $form_state->setValues($values);
    }
    $values = NestedArray::mergeDeep($this->defaultValues(), (array) $values);
    if (isset($widget_state['repeat_setting'])) {
      $values['repeat'] = $widget_state['repeat_setting'];
    }


    $wrapper_id = implode('-', $selector_parents);
    $wrapper_id = Html::getId($wrapper_id);


    $element = parent::formElement($items, $delta, $element, $form, $form_state);


    $element['rrule']['#type'] = 'value';

    // Restrict repeat options to formatter options. Allow existing options
    // outside of formatter.
    $repeat_options = DateRecurRruleMap::frequencyLabels();
    $repeat_settings = $this->getSetting('allowed_repeat_types');
    if ($values['repeat']) {
      $repeat_settings[$values['repeat']] = $values['repeat'];
    }
    $repeat_options = array_intersect_key($repeat_options, array_filter($repeat_settings));

    $repeat_label = [
      'YEARLY' => 'Year(s)',
      'MONTHLY' => 'Month(s)',
      'WEEKLY' => 'Week(s)',
      'DAILY' => 'Day(s)',
      'HOURLY' => 'Hour(s)',
      'MINUTELY' => 'Minute(s)',
      'SECONDLY' => 'Second(s)',
    ];

    //@todo: Make intervals configurable.
    $element['repeat'] = [
      '#title' => 'Repeat',
      '#type' => 'select',
      '#name' => $repeat_selector,
      '#empty_option' => '- None -',
      '#options' => $repeat_options,
      '#ajax' => [
        'callback' => [$this, 'addRepeatAjax'],
        'wrapper' => $wrapper_id,
        'trigger_as' => ['name' => $repeat_configure_selector],
      ],
      '#default_value' => $values['repeat'],
    ];
    // @todo: Fix this button being on form instead of element.
    $element['repeat_configure'] = [
      '#type' => 'submit',
      '#name' => $repeat_configure_selector,
      '#value' => t('Configure'),
      '#limit_validation_errors' => [$repeat_parents],
      '#submit' => [[$this, 'addRepeat']],
      '#ajax' => [
        'callback' => [$this, 'addRepeatAjax'],
        'wrapper' => $wrapper_id,
      ],
      '#weight' => -10,
      '#attributes' => ['class' => ['js-hide']],
    ];
    $settings = [
      '#type' => 'fieldset',
      '#prefix' => '<div id="' . $wrapper_id . '">',
      '#suffix' => '</div>',
    ];
    if (!$values['repeat']) {
      $settings['#attributes']['class'] = ['js-hide'];
    }
    else {
      $settings['interval'] = [
        '#title' => 'Interval',
        '#title_display' => 'hidden',
        '#prefix' => 'Every',
        '#suffix' => $repeat_label[$values['repeat']],
        '#type' => 'number',
        '#min' => 1,
        '#required' => TRUE,
        '#wrapper_attributes' => [
          'class' => [
            'container-inline',
            'form-inline',
          ],
        ],
        '#attributes' => ['class' => ['container-inline', 'form-inline']],
        '#default_value' => $values['repeat_settings']['interval'],
      ];
      $settings['until'] = [
        '#title' => 'Until',
        '#type' => 'radios',
        '#options' => [],
        '#value' => NULL,
        '#default_value' => $values['repeat_settings']['until_op'],
      ];
      $settings['until']['never'] = ['#type' => 'container'];
      $settings['until']['never']['value'] = [
        '#title' => 'Never',
        '#type' => 'radio',
        '#return_value' => 'never',
        '#default_value' => $values['repeat_settings']['until_op'],
        '#parents' => array_merge($selector_parents, [
          'repeat_settings',
          'until_op',
        ]),
      ];
      $settings['until']['count'] = [
        '#type' => 'container',
        '#wrapper_attributes' => [
          'class' => [
            'container-inline',
            'form-inline',
          ],
        ],
        '#attributes' => ['class' => ['container-inline', 'form-inline']],
      ];
      $settings['until']['count']['value'] = [
        '#title' => 'After',
        '#type' => 'radio',
        '#return_value' => 'count',
        '#default_value' => $values['repeat_settings']['until_op'],
        '#parents' => array_merge($selector_parents, [
          'repeat_settings',
          'until_op',
        ]),
        '#wrapper_attributes' => [
          'class' => [
            'container-inline',
            'form-inline',
          ],
        ],
        '#attributes' => ['class' => ['container-inline', 'form-inline']],
      ];

      $settings['until']['count']['until_count'] = [
        '#title' => 'Count',
        '#title_display' => 'hidden',
        '#suffix' => 'Occurrences',
        '#type' => 'number',
        '#min' => 1,
        '#wrapper_attributes' => [
          'class' => [
            'container-inline',
            'form-inline',
          ],
        ],
        '#attributes' => ['class' => ['container-inline', 'form-inline']],
        '#default_value' => $values['repeat_settings']['until']['count']['until_count'],
      ];

      $settings['until']['date'] = [
        '#type' => 'container',
        '#wrapper_attributes' => [
          'class' => [
            'container-inline',
            'form-inline',
          ],
        ],
        '#attributes' => ['class' => ['container-inline', 'form-inline']],
      ];
      $settings['until']['date']['value'] = [
        '#title' => 'Date',
        '#type' => 'radio',
        '#return_value' => 'date',
        '#default_value' => $values['repeat_settings']['until_op'],
        '#parents' => array_merge($selector_parents, [
          'repeat_settings',
          'until_op',
        ]),
        '#wrapper_attributes' => [
          'class' => [
            'container-inline',
            'form-inline',
          ],
        ],
        '#attributes' => ['class' => ['container-inline', 'form-inline']],
      ];

      $date_value = $values['repeat_settings']['until']['date']['until_date'];
      if ($date_value instanceof DrupalDateTime) {
        $date_value = $date_value->format(DateTimeItemInterface::DATE_STORAGE_FORMAT);
      }

      $settings['until']['date']['until_date'] = [
        '#title' => '',
        '#title_display' => 'invisible',
        '#type' => 'date',
        '#default_value' => $date_value,
      ];

      if (in_array($values['repeat'], ['MONTHLY', 'WEEKLY'], TRUE)) {
        $settings['day_of_week'] = [
          '#title' => 'Day of week',
          '#type' => 'checkboxes',
          '#options' => DateHelper::weekDays(TRUE),
          '#wrapper_attributes' => ['class' => ['container-inline']],
          '#attributes' => ['class' => ['container-inline']],
          '#default_value' => $values['repeat_settings']['day_of_week'],
        ];
      }

      if ($values['repeat'] == 'MONTHLY') {
        $settings['week_of_month'] = [
          '#title' => 'Week',
          '#type' => 'checkboxes',
          '#options' => [
            '+1' => 'First',
            '+2' => 'Second',
            '+3' => 'Third',
            '+4' => 'Fourth',
            '+5' => 'Fifth',
            '-1' => 'Last',
          ],
          '#wrapper_attributes' => ['class' => ['container-inline']],
          '#attributes' => ['class' => ['container-inline']],
          '#default_value' => $values['repeat_settings']['week_of_month'],
        ];
      }

      if ($values['repeat'] == 'MONTHLY') {
        $settings['month_of_year'] = [
          '#title' => 'Only in',
          '#type' => 'checkboxes',
          '#options' => DateHelper::monthNamesAbbr(TRUE),
          '#wrapper_attributes' => ['class' => ['container-inline']],
          '#attributes' => ['class' => ['container-inline']],
          '#default_value' => $values['repeat_settings']['month_of_year'],
        ];
      }
    }

    $element['repeat_settings'] = $settings;

    return $element;
  }

  public function addRepeat(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $parents = array_slice($trigger['#parents'], 0, -1);
    $repeat_parents = $parents;
    $repeat_parents[] = 'repeat';
    $field_name = $this->fieldDefinition->getName();
    $field_state = static::getWidgetState($form['#parents'], $field_name, $form_state);
    $field_state['repeat_setting'] = NestedArray::getValue($form_state->getValues(), $repeat_parents);
    static::setWidgetState($form['#parents'], $field_name, $form_state, $field_state);
    $form_state->setRebuild();
  }

  public function addRepeatAjax(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();

    // Go one level up in the form, to the widgets container.
    $element = NestedArray::getValue($form, array_slice($trigger['#array_parents'], 0, -1))['repeat_settings'];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    //@todo: add validation.
    foreach ($values as $delta => $value) {
      if (!empty($value['repeat'])) {
        $value = NestedArray::mergeDeep($this->defaultValues(), (array) $value);
        $value = self::getRRuleFromValue($value);
      }
      $values[$delta] = $value;
    }
    return parent::massageFormValues($values, $form, $form_state);
  }

  public static function getValueFromRRule($rrule) {
    $test = DateRecurHelper::create($rrule, new \DateTime());
    /** @var \Drupal\date_recur\DateRecurRuleInterface[] $rules */
    $rules = $test->getRules();
    $parts = $rules[0]->getParts();
    $values = [
      'repeat' => $parts['FREQ'],
      'repeat_settings' => [
        'interval' => isset($parts['INTERVAL']) ? $parts['INTERVAL'] : 1,
      ],
    ];

    if (!empty($parts['COUNT'])) {
      $values['repeat_settings']['until_op'] = 'count';
      $values['repeat_settings']['until']['count']['until_count'] = $parts['COUNT'];
    }
    elseif (!empty($parts['UNTIL'])) {
      $values['repeat_settings']['until_op'] = 'date';
      $values['repeat_settings']['until']['date']['until_date'] = DrupalDateTime::createFromDateTime($parts['UNTIL']);
    }

    if (!empty($parts['BYDAY'])) {
      $days = explode(',', $parts['BYDAY']);
      $weeks = [];

      foreach ($days as $i => $day) {
        if (strlen($day) > 2) {
          $weeks[] = substr($day, 0, 2);
          $days[$i] = substr($day, 2, 2);
        }
      }

      $days = array_unique($days);

      if ($weeks) {
        $weeks = array_unique($weeks);
        $values['repeat_settings']['week_of_month'] = $weeks;
      }

      $days_map = array_map('strtoupper', DateHelper::weekDaysAbbr2());
      $values['repeat_settings']['day_of_week'] = array_keys(array_intersect($days_map, $days));
    }

    if (!empty($parts['BYMONTH'])) {
      $values['repeat_settings']['month_of_year'] = explode(',', $parts['BYMONTH']);
    }

    return $values;
  }

  public static function getRRuleFromValue($value) {
    $parts = [
      'FREQ' => $value['repeat'],
      'INTERVAL' => $value['repeat_settings']['interval'],
    ];

    switch ($value['repeat_settings']['until_op']) {
      case 'count':
        $parts['COUNT'] = $value['repeat_settings']['until']['count']['until_count'];
        break;

      case 'date':
        $date = DrupalDateTime::createFromFormat(DateTimeItemInterface::DATE_STORAGE_FORMAT, $value['repeat_settings']['until']['date']['until_date']);
        $parts['UNTIL'] = $date;
        break;
    }

    if (in_array($value['repeat'], ['MONTHLY', 'WEEKLY'], TRUE) && ($days = array_filter($value['repeat_settings']['day_of_week']))) {
      $days_map = array_map('strtoupper', DateHelper::weekDaysAbbr2());
      $days = array_intersect_key($days_map, array_flip($days));

      if (!empty($value['repeat_settings']['week_of_month']) && ($weeks = array_filter($value['repeat_settings']['week_of_month']))) {
        $days_weeks = [];
        foreach ($days as $day) {
          foreach ($weeks as $week) {
            $days_weeks[] = $week . $day;
          }
        }
        $parts['BYDAY'] = implode(',', $days_weeks);
      }
      else {
        $parts['BYDAY'] = implode(',', $days);
      }
    }


    if (!empty($value['repeat_settings']['month_of_year'])) {
      if ($months = array_filter($value['repeat_settings']['month_of_year'])) {
        $parts['BYMONTH'] = implode(',', $months);
      }
    }

    $rrule = new RRule($parts);
    $value['rrule'] = 'RRULE:' . (string) $rrule;

    return $value;
  }

}
