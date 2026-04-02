(() => {
    const PHONE_PARTS = ["+7", "903", "123", "61", "76"];
    const PHONE_DISPLAY = `${PHONE_PARTS[0]} (${PHONE_PARTS[1]}) ${PHONE_PARTS[2]}-${PHONE_PARTS[3]}-${PHONE_PARTS[4]}`;
    const PHONE_MASKED = `${PHONE_PARTS[0]} (${PHONE_PARTS[1]}) ***-**-${PHONE_PARTS[4]}`;
    const PHONE_LINK = `tel:${PHONE_PARTS[0]}${PHONE_PARTS[1]}${PHONE_PARTS[2]}${PHONE_PARTS[3]}${PHONE_PARTS[4]}`;
    const COOKIE_KEY = "protect_cookie_notice_v2";
    const REQUESTED_WITH_HEADER = {
        "X-Requested-With": "XMLHttpRequest",
    };

    function setBodyScrollLocked(isLocked) {
        document.body.style.overflow = isLocked ? "hidden" : "";
    }

    function formatPhone(value) {
        let digits = value.replace(/\D/g, "");

        if (digits.startsWith("7") || digits.startsWith("8")) {
            digits = digits.slice(1);
        }

        digits = digits.slice(0, 10);

        let formatted = "+7";
        if (digits.length > 0) formatted += ` (${digits.slice(0, 3)}`;
        if (digits.length >= 4) formatted += `) ${digits.slice(3, 6)}`;
        if (digits.length >= 7) formatted += `-${digits.slice(6, 8)}`;
        if (digits.length >= 9) formatted += `-${digits.slice(8, 10)}`;

        return formatted;
    }

    function normalizeRuPhone(value, allowTenDigits = false) {
        let digits = value.replace(/\D/g, "");

        if (digits.startsWith("8")) {
            digits = "7" + digits.slice(1);
        }

        if (allowTenDigits && digits.length === 10) {
            digits = "7" + digits;
        }

        return digits;
    }

    function isRuMobilePhone(value) {
        const digits = normalizeRuPhone(value, true);
        return digits.length === 11 && digits[0] === "7" && digits[1] === "9";
    }

    async function postLead(payload) {
        const response = await fetch("/send-email.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                ...REQUESTED_WITH_HEADER,
            },
            credentials: "same-origin",
            body: JSON.stringify(payload),
        });

        const result = await response.json().catch(() => ({
            success: false,
            message: "Некорректный ответ сервера.",
        }));

        if (!response.ok || !result.success) {
            throw new Error(result.message || "Не удалось отправить заявку.");
        }

        return result;
    }

    function revealPhone(target) {
        if (!target) return;
        if (target.tagName === "A") target.href = PHONE_LINK;
        target.textContent = PHONE_DISPLAY;
        target.dataset.phoneVisible = "true";
    }

    function setupPhoneReveal() {
        document.querySelectorAll("[data-reveal-phone]").forEach((el) => {
            if (el.dataset.phoneVisible !== "true") {
                el.textContent = PHONE_MASKED;
            }
            el.addEventListener("click", () => {
                if (el.dataset.phoneVisible === "true") return;
                revealPhone(el);
            });
        });
    }

    function setupMobileMenu() {
        const toggle = document.querySelector(".menu-toggle");
        const nav = document.querySelector(".nav");

        if (!toggle || !nav) return;

        const closeBtn = nav.querySelector(".nav-close");

        function openNav() {
            nav.classList.add("is-open");
            toggle.setAttribute("aria-expanded", "true");
            setBodyScrollLocked(true);
        }

        function closeNav() {
            nav.classList.remove("is-open");
            toggle.setAttribute("aria-expanded", "false");
            setBodyScrollLocked(false);
        }

        toggle.addEventListener("click", () => {
            if (nav.classList.contains("is-open")) {
                closeNav();
            } else {
                openNav();
            }
        });

        if (closeBtn) {
            closeBtn.addEventListener("click", closeNav);
        }

        nav.querySelectorAll("a").forEach((link) => {
            link.addEventListener("click", closeNav);
        });
    }

    function setupCookieBar() {
        const bar = document.getElementById("cookie-bar");
        const btn = document.getElementById("accept-cookies");

        if (!bar || !btn) return;

        if (!localStorage.getItem(COOKIE_KEY)) bar.hidden = false;

        btn.addEventListener("click", () => {
            localStorage.setItem(COOKIE_KEY, "accepted");
            bar.hidden = true;
        });
    }

    function setupConsultModal() {
        const modal = document.getElementById("consult-modal");
        if (!modal) return;

        const openButtons = document.querySelectorAll(".js-open-consultation");
        const closeButtons = modal.querySelectorAll("[data-close-modal]");

        function openModal() {
            modal.hidden = false;
            setBodyScrollLocked(true);
        }

        function closeModal() {
            modal.hidden = true;
            setBodyScrollLocked(false);
        }

        openButtons.forEach((btn) => btn.addEventListener("click", openModal));
        closeButtons.forEach((btn) => btn.addEventListener("click", closeModal));

        document.addEventListener("keydown", (e) => {
            if (e.key === "Escape" && !modal.hidden) closeModal();
        });
    }

    function setupPolicyModal() {
        const modal = document.getElementById("policy-modal");
        const content = document.getElementById("policy-content");
        if (!modal || !content) return;

        const links = document.querySelectorAll(".js-policy-link");
        const closeButtons = modal.querySelectorAll("[data-close-policy]");
        let currentUrl = "";

        async function loadPolicy(url) {
            const response = await fetch(url, {
                method: "GET",
                credentials: "same-origin",
                headers: REQUESTED_WITH_HEADER,
            });
            if (!response.ok) {
                throw new Error("Не удалось загрузить документ.");
            }

            const html = await response.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, "text/html");
            const card = doc.querySelector(".doc-card");
            const title = doc.querySelector("h1");
            if (!card) {
                throw new Error("Документ имеет неподдерживаемый формат.");
            }

            if (title) {
                modal.querySelector(".modal-dialog")?.setAttribute("aria-label", title.textContent.trim());
            }

            content.innerHTML = card.outerHTML;
        }

        async function openPolicy(url) {
            currentUrl = url;
            content.innerHTML = '<div class="policy-loading">Загрузка документа...</div>';
            modal.hidden = false;
            setBodyScrollLocked(true);

            try {
                await loadPolicy(url);
            } catch (error) {
                const message =
                    error?.message || "Не удалось открыть документ. Попробуйте позже.";
                content.innerHTML = `<div class="policy-error">${message}</div>`;
            }
        }

        function closePolicy() {
            modal.hidden = true;
            content.innerHTML = "";
            currentUrl = "";
            setBodyScrollLocked(false);
        }

        links.forEach((link) => {
            link.addEventListener("click", (e) => {
                const href = link.getAttribute("href");
                if (!href) return;
                e.preventDefault();
                openPolicy(href);
            });
        });

        closeButtons.forEach((btn) => btn.addEventListener("click", closePolicy));

        content.addEventListener("click", (e) => {
            const target = e.target.closest("a");
            if (!target) return;
            const href = target.getAttribute("href");
            if (!href || !href.endsWith(".html")) return;
            e.preventDefault();
            if (href === currentUrl) return;
            openPolicy(href);
        });

        document.addEventListener("keydown", (e) => {
            if (e.key === "Escape" && !modal.hidden) closePolicy();
        });
    }

    function setupHeroImageFallback() {
        const heroImg = document.querySelector(".hero-bg-image");
        if (!heroImg) return;
        heroImg.addEventListener("error", () => {
            if (!heroImg.src.includes("/Igor.jpg")) {
                heroImg.src = "/Igor.jpg";
            }
        });
    }

    function setupForm() {
        const form = document.getElementById("lead-form");
        const status = document.getElementById("form-status");
        const phoneInput = document.getElementById("lead-phone");
        const sentAtInput = document.getElementById("sent-at");

        if (!form || !status || !phoneInput || !sentAtInput) return;

        sentAtInput.value = String(Date.now());

        phoneInput.addEventListener("input", (e) => {
            e.target.value = formatPhone(e.target.value);
        });

        form.addEventListener("submit", async (e) => {
            e.preventDefault();

            status.textContent = "";
            status.className = "form-status";

            const formData = new FormData(form);
            const phone = (formData.get("phone") || "").toString();
            const digits = phone.replace(/\D/g, "");

            if (digits.length < 11) {
                status.textContent = "Укажите корректный номер телефона.";
                status.classList.add("is-error");
                return;
            }

            const normalized = normalizeRuPhone(phone);

            if (normalized[0] !== "7") {
                status.textContent = "Принимаются только российские номера.";
                status.classList.add("is-error");
                return;
            }

            if (normalized[1] !== "9") {
                status.textContent = "Принимаются только мобильные номера (+7 9...).";
                status.classList.add("is-error");
                return;
            }

            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;

            const payload = {
                name: (formData.get("name") || "").toString().trim(),
                phone,
                company: (formData.get("company") || "").toString(),
                consent: formData.get("consent") ? "yes" : "no",
                sent_at: formData.get("sent_at"),
                source: (formData.get("source") || "main-form").toString(),
                page: window.location.href,
            };

            try {
                const result = await postLead(payload);

                form.reset();
                sentAtInput.value = String(Date.now());
                status.textContent =
                    result.message ||
                    "Заявка отправлена. Свяжусь с вами в ближайшее время.";
                status.classList.add("is-success");
            } catch (error) {
                status.textContent =
                    error.message ||
                    "Не удалось отправить заявку. Попробуйте позже или напишите в Telegram.";
                status.classList.add("is-error");
            } finally {
                if (submitBtn) submitBtn.disabled = false;
            }
        });
    }

    function setupQuiz() {
        const shell = document.querySelector(".quiz-shell");
        if (!shell) return;

        const screens = Array.from(shell.querySelectorAll(".quiz-screen"));
        const progressItems = Array.from(shell.querySelectorAll(".quiz-progress span"));
        const backBtn = shell.querySelector(".quiz-back");
        const nextBtn = shell.querySelector(".quiz-next");
        const stepLabel = shell.querySelector(".quiz-step");
        const errorLabel = shell.querySelector(".quiz-error");
        const statusLabel = document.getElementById("quiz-status");
        const contactHidden = document.getElementById("quiz-contact-method");
        const quizSentAt = String(Date.now());

        if (!screens.length || !backBtn || !nextBtn || !stepLabel) return;

        let currentStep = 1;
        const totalSteps = screens.length;

        function clearMessages() {
            if (errorLabel) errorLabel.textContent = "";
            if (statusLabel) {
                statusLabel.textContent = "";
                statusLabel.className = "quiz-status";
            }
        }

        function renderStep() {
            screens.forEach((screen, idx) => {
                screen.classList.toggle("is-active", idx + 1 === currentStep);
            });

            progressItems.forEach((item, idx) => {
                item.classList.toggle("is-active", idx < currentStep);
            });

            stepLabel.textContent = `Шаг ${currentStep}/${totalSteps}`;
            backBtn.disabled = currentStep === 1;
            clearMessages();
            nextBtn.textContent =
                currentStep === totalSteps ? "Получить консультацию" : "Далее";
            nextBtn.classList.remove("js-open-consultation");
        }

        function hasChecked(name) {
            return shell.querySelectorAll(`input[name="${name}"]:checked`).length > 0;
        }

        function showError(message) {
            if (errorLabel) errorLabel.textContent = message;
        }

        function validateStep(step) {
            if (step === 1 && !hasChecked("debts")) {
                showError("Выберите хотя бы один вариант.");
                return false;
            }
            if (step === 2 && !hasChecked("amount")) {
                showError("Выберите сумму долга.");
                return false;
            }
            if (step === 3 && !hasChecked("late")) {
                showError("Выберите один вариант.");
                return false;
            }
            if (step === 4 && !hasChecked("property")) {
                showError("Выберите хотя бы один вариант.");
                return false;
            }
            if (step === 5 && !hasChecked("official_income")) {
                showError("Выберите один вариант.");
                return false;
            }
            if (step === 6) {
                const city = shell.querySelector('input[name="quiz_city"]');
                if (!city || !city.value.trim()) {
                    showError("Укажите ваш город.");
                    return false;
                }
            }
            if (step === 7) {
                const phoneInput = shell.querySelector('input[name="quiz_phone"]');
                const consent = shell.querySelector("#quiz-consent");
                if (!phoneInput || !isRuMobilePhone(phoneInput.value)) {
                    showError("Укажите корректный мобильный номер (+7 9…).");
                    return false;
                }
                if (!consent || !consent.checked) {
                    showError("Подтвердите согласие на обработку данных.");
                    return false;
                }
            }
            return true;
        }

        function collectQuizAnswers() {
            const checkedValues = (name) =>
                [...shell.querySelectorAll(`input[name="${name}"]:checked`)].map(
                    (el) => el.value
                );
            return {
                debts: checkedValues("debts"),
                amount: checkedValues("amount"),
                late: shell.querySelector('input[name="late"]:checked')?.value || "",
                property: checkedValues("property"),
                official_income:
                    shell.querySelector('input[name="official_income"]:checked')
                        ?.value || "",
                city: shell.querySelector('input[name="quiz_city"]')?.value?.trim() || "",
                contact_method: contactHidden?.value || "phone",
            };
        }

        shell.addEventListener("input", (e) => {
            const target = e.target;
            if (target && target.name === "quiz_phone") {
                target.value = formatPhone(target.value);
            }
        });

        shell.querySelectorAll("[data-contact]").forEach((btn) => {
            btn.addEventListener("click", () => {
                const value = btn.getAttribute("data-contact") || "phone";
                shell.querySelectorAll("[data-contact]").forEach((b) => {
                    const active = b === btn;
                    b.classList.toggle("is-active", active);
                    b.setAttribute("aria-pressed", active ? "true" : "false");
                });
                if (contactHidden) contactHidden.value = value;
            });
        });

        backBtn.addEventListener("click", () => {
            if (currentStep > 1) {
                currentStep -= 1;
                renderStep();
            }
        });

        nextBtn.addEventListener("click", async () => {
            if (!validateStep(currentStep)) return;

            if (currentStep < totalSteps) {
                currentStep += 1;
                renderStep();
                return;
            }

            const phoneInput = shell.querySelector('input[name="quiz_phone"]');
            const nameInput = shell.querySelector("#quiz-name");
            const consent = shell.querySelector("#quiz-consent");
            const submitBtn = nextBtn;

            submitBtn.disabled = true;
            clearMessages();

            const payload = {
                name: (nameInput?.value || "").trim(),
                phone: phoneInput?.value || "",
                company: "",
                consent: consent?.checked ? "yes" : "no",
                sent_at: quizSentAt,
                source: "quiz-bankruptcy",
                page: window.location.href,
                quiz_answers: collectQuizAnswers(),
            };

            try {
                const result = await postLead(payload);

                if (statusLabel) {
                    statusLabel.textContent =
                        result.message ||
                        "Заявка отправлена. Свяжусь с вами в ближайшее время.";
                    statusLabel.className = "quiz-status is-success";
                }
            } catch (error) {
                showError(
                    error.message ||
                        "Не удалось отправить заявку. Попробуйте позже."
                );
                submitBtn.disabled = false;
            }
        });

        renderStep();
    }

    function setupYear() {
        const el = document.getElementById("current-year");
        if (el) el.textContent = String(new Date().getFullYear());
    }

    function setupMobMessenger() {
        const wrap = document.getElementById("mob-messenger");
        const toggle = document.getElementById("mob-messenger-toggle");
        const links = document.getElementById("mob-messenger-links");
        const iconTg = wrap?.querySelector(".mob-messenger-icon--tg");
        const iconMax = wrap?.querySelector(".mob-messenger-icon--max");

        if (!wrap || !toggle || !links || !iconTg || !iconMax) return;

        let showTg = true;
        setInterval(() => {
            showTg = !showTg;
            iconTg.classList.toggle("is-active", showTg);
            iconMax.classList.toggle("is-active", !showTg);
        }, 5000);

        let isOpen = false;

        toggle.addEventListener("click", () => {
            isOpen = !isOpen;
            links.classList.toggle("is-open", isOpen);
        });

        document.addEventListener("click", (e) => {
            if (isOpen && !wrap.contains(e.target)) {
                isOpen = false;
                links.classList.remove("is-open");
            }
        });
    }

    function setupLawRightsMarquee() {
        const tracks = document.querySelectorAll(".law-rights-tags .marquee-track");
        if (!tracks.length) return;

        // Дублируем контент только на мобильной ширине,
        // иначе на десктопе теги окажутся вдвое длиннее.
        const isMobile = window.matchMedia && window.matchMedia("(max-width: 768px)").matches;
        if (!isMobile) return;

        tracks.forEach((track) => {
            if (track.dataset.marqueeDuplicated === "true") return;

            const originalChildren = Array.from(track.children);
            if (!originalChildren.length) return;

            const frag = document.createDocumentFragment();
            originalChildren.forEach((node) => frag.appendChild(node.cloneNode(true)));
            track.appendChild(frag);

            track.dataset.marqueeDuplicated = "true";
        });
    }

    function setupDesktopPhoneTail() {
        const toggle = document.querySelector(".desktop-phone-toggle");
        if (!toggle) return;

        function setOpen(isOpen) {
            toggle.classList.toggle("is-open", isOpen);
            toggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
        }

        toggle.addEventListener("click", (e) => {
            e.stopPropagation();
            const isOpen = !toggle.classList.contains("is-open");
            setOpen(isOpen);
        });

        document.addEventListener("click", () => {
            if (toggle.classList.contains("is-open")) setOpen(false);
        });

        document.addEventListener("keydown", (e) => {
            if (e.key === "Escape") setOpen(false);
        });
    }

    document.addEventListener("DOMContentLoaded", () => {
        setupYear();
        setupMobileMenu();
        setupConsultModal();
        setupPolicyModal();
        setupHeroImageFallback();
        setupPhoneReveal();
        setupDesktopPhoneTail();
        setupCookieBar();
        setupForm();
        setupQuiz();
        setupMobMessenger();
        setupLawRightsMarquee();
    });
})();
