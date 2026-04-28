document.addEventListener("DOMContentLoaded", function () {
  const signUpButton = document.getElementById("signUpButton");
  const signInButton = document.getElementById("signInButton");
  const signUpContainer = document.getElementById("signup");
  const signInContainer = document.getElementById("signIn");

  signUpButton.addEventListener("click", () => {
    signUpContainer.style.display = "block";
    signInContainer.style.display = "none";
  });

  signInButton.addEventListener("click", () => {
    signUpContainer.style.display = "none";
    signInContainer.style.display = "block";
  });
});
