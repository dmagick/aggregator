drop table aggregator_users;
drop table aggregator_user_login_locks;

create table aggregator_user_login_locks
(
  ip text,
  start_time timestamp,
  end_time timestamp,
  attempts int default 0
);
create index aggregator_user_login_locks_details on aggregator_user_login_locks(ip, start_time, end_time);

create table aggregator_users
(
  user_id serial not null primary key,
  username text unique not null,
  passwd text not null,
  useractive char(1) default 'n'
);

