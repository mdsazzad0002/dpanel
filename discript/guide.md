sudo visudo -f /etc/sudoers.d/dpanel-vhosts

www-data ALL=(root) NOPASSWD: /home/bdsoft/likesoftbd_com/discript/scripts/sync-vhost.sh *
bdsoft ALL=(root) NOPASSWD: /home/bdsoft/likesoftbd_com/discript/scripts/sync-vhost.sh *
www-data ALL=(root) NOPASSWD: /home/bdsoft/likesoftbd_com/discript/scripts/fix-panel-web-stack.sh *
www-data ALL=(root) NOPASSWD: /home/bdsoft/likesoftbd_com/discript/scripts/fix-web-stack.sh *


sudo chmod 440 /etc/sudoers.d/dpanel-vhosts
sudo visudo -cf /etc/sudoers.d/dpanel-vhosts