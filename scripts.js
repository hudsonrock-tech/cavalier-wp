document.addEventListener("DOMContentLoaded", function () {
  var closeButtons = document.querySelectorAll(".close-notice");

  closeButtons.forEach(function (button) {
    button.addEventListener("click", function (event) {
      event.preventDefault();
      var token = this.getAttribute("data-token");
      var type = this.getAttribute("data-value");
      var noticeBar = this.closest(".cavalier-notice-bar");
      if (noticeBar) {
        noticeBar.style.display = "none";
        fetch(
          `https://cavalier.hudsonrock.com/api/wp/resolve?type=${type}&token=${token}`,
          {
            method: "POST",
            headers: {
              "Content-Type": "application/json",
            },
          }
        )
          .then((response) => {
            console.log(response.data);
          })
          .catch((error) => {
            console.error("Error:", error);
          });
      }
    });
  });
});
