document.addEventListener("DOMContentLoaded", function () {
    var sliders = document.querySelectorAll(".show-slider");
    sliders.forEach((slider) => {
        new Swiper(slider, {
            loop: true,
            navigation: {
                nextEl: ".swiper-button-next",
                prevEl: ".swiper-button-prev",
            },
            
            pagination: {
                el: ".swiper-pagination",
                clickable: true,
            },
        });
    });
});