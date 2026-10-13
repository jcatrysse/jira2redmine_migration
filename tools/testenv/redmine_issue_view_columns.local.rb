# Test environment: relation types from config.default.php migration.relations.custom_mappings
RedmineIssueViewColumns::RelationTypes.register!(
  {
    'relates_to_wiki' => { name: :label_relates_to_wiki, sym_name: :label_relates_from_wiki, order: 1.1, sym: 'relates_from_wiki' },
    'relates_from_wiki' => { name: :label_relates_from_wiki, sym_name: :label_relates_to_wiki, order: 1.2, sym: 'relates_to_wiki' },
    'relates_to_labo_defective_eq' => { name: :label_relates_to_labo_defective_eq, sym_name: :label_relates_from_labo_defective_eq, order: 1.3, sym: 'relates_from_labo_defective_eq' },
    'relates_from_labo_defective_eq' => { name: :label_relates_from_labo_defective_eq, sym_name: :label_relates_to_labo_defective_eq, order: 1.4, sym: 'relates_to_labo_defective_eq' },
    'relates_to_labo_task' => { name: :label_relates_to_labo_task, sym_name: :label_relates_from_labo_task, order: 1.5, sym: 'relates_from_labo_task' },
    'relates_from_labo_task' => { name: :label_relates_from_labo_task, sym_name: :label_relates_to_labo_task, order: 1.6, sym: 'relates_to_labo_task' },
    'relates_to_create_rma' => { name: :label_relates_to_create_rma, sym_name: :label_relates_from_create_rma, order: 1.7, sym: 'relates_from_create_rma' },
    'relates_from_create_rma' => { name: :label_relates_from_create_rma, sym_name: :label_relates_to_create_rma, order: 1.8, sym: 'relates_to_create_rma' },
    'relates_to_data_support' => { name: :label_relates_to_data_support, sym_name: :label_relates_from_data_support, order: 1.9, sym: 'relates_from_data_support' },
    'relates_from_data_support' => { name: :label_relates_from_data_support, sym_name: :label_relates_to_data_support, order: 2.0, sym: 'relates_to_data_support' },
    'relates_to_polaris_work_item' => { name: :label_relates_to_polaris_work_item, sym_name: :label_relates_from_polaris_work_item, order: 2.1, sym: 'relates_from_polaris_work_item' },
    'relates_from_polaris_work_item' => { name: :label_relates_from_polaris_work_item, sym_name: :label_relates_to_polaris_work_item, order: 2.2, sym: 'relates_to_polaris_work_item' },
    'relates_to_cloners' => { name: :label_relates_to_cloners, sym_name: :label_relates_from_cloners, order: 2.3, sym: 'relates_from_cloners' },
    'relates_from_cloners' => { name: :label_relates_from_cloners, sym_name: :label_relates_to_cloners, order: 2.4, sym: 'relates_to_cloners' }
  },
  relates_like: %w[relates_to_wiki relates_from_wiki relates_to_labo_defective_eq relates_from_labo_defective_eq relates_to_labo_task relates_from_labo_task relates_to_create_rma relates_from_create_rma relates_to_data_support relates_from_data_support relates_to_polaris_work_item relates_from_polaris_work_item relates_to_cloners relates_from_cloners]
)
