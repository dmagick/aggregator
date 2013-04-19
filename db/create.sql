drop table aggregator_users_feeds;
drop table aggregator_users_urls;
drop table aggregator_urls;
drop table aggregator_feeds;

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
    user_id serial not null,
    username text not null primary key,
    passwd text not null,
    useractive char(1) default 'n'
);

create table aggregator_feeds
(
    feed_url text not null primary key,
    feed_title text,
    last_checked timestamp,
    last_status int,
    feed_hash text
);

create table aggregator_urls
(
    url text not null primary key,
    url_description text,
    url_title text,
    feed_url text references aggregator_feeds(feed_url) on delete cascade,
    last_checked timestamp,
    status int
);

create table aggregator_users_feeds
(
    username text not null references aggregator_users(username) on delete cascade,
    feed_url text not null references aggregator_feeds(feed_url) on delete cascade,
    user_checked timestamp
);

create table aggregator_users_urls
(
    username text not null references aggregator_users(username) on delete cascade,
    url text not null references aggregator_urls(url) on delete cascade,
    url_description text,
    url_title text,
    user_checked timestamp
);

