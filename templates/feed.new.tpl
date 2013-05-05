<div class="middle">
    <h2>Add a new feed</h2>
    ~flashmessage~
    <form method="post" action="~url::baseurl~/feed/new">
        <div class="account-field">
            URL:
        </div>
        <div class="account-value">
            <input type="text" name="feed_url" id="feed_url" value="http://" />
        </div>

        <div class="account-field">
            Title (optional):
        </div>
        <div class="account-value">
            <input type="text" name="feed_title" id="feed_title" />
        </div>

        <div class="account-field">
            Group (optional):
        </div>
        <div class="account-value">
            <input type="text" name="groupname" id="groupname" />
        </div>

        <div class="account-value">
            <br/>
            <input type="submit" value="Add Feed" />
        </div>
    </form>
</div>

