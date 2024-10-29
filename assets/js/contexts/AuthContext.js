import React from 'react';


export default React.createContext({
    isAuthenticated: false,
    setIsAuthenticated: (value) => {},

    userName: "",
    setUserName: (value) => {},

    role: "",
    setRole: (value) => {},
})