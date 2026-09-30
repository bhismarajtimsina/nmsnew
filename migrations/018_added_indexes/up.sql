create index collector_processing_start_at_index
    on collector_processing (start_at);

create index collector_processing_collector_index
    on collector_processing (collector);

create index system_actions_action_index
    on system_actions (action);

create index system_actions_created_at_index
    on system_actions (created_at);

create index system_schedule_reports_start_at_index
    on system_schedule_reports (start_at);

