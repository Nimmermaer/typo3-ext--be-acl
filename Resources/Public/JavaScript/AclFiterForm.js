import $ from 'jquery';

$(document).ready(function () {
    const updateButton = document.getElementById('BeAclUpdateButton');
    if (updateButton) {
        updateButton.addEventListener('click', () => {
            const form = document.aclfilterform;
            if (form) {
                form.action = document.location;
                form.submit();
            }
        });
    }
});
export default function () {}
